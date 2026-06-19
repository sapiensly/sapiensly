<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\Workflows\WorkflowAssertionEvaluator;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Verifies a workflow by dry-run (FR-2): seeds the trigger payload, executes
 * with every write SIMULATED (never applied), then evaluates a closed set of
 * declarative assertions and returns a legible pass/fail report. External writes
 * surface as Proposal previews; internal record writes never touch real records.
 *
 * The tool is the engine of the bounded build→verify→repair loop (FR-2.6): it
 * counts verification attempts per workflow within the turn and refuses to keep
 * looping — after MAX_ATTEMPTS, or when the same failure signature recurs after
 * a fix (oscillation), it returns stop_repairing=true so Claude surfaces the
 * failure to the user with what it tried, instead of patching forever.
 */
class VerifyWorkflowTool implements Tool
{
    private const MAX_ATTEMPTS = 3;

    /** @var array<string, int> Verification attempts so far, per workflow id. */
    private array $attempts = [];

    /** @var array<string, string> Last failure signature seen, per workflow id. */
    private array $lastSignature = [];

    public function __construct(
        private App $app,
        private WorkflowEngine $engine,
        private WorkflowAssertionEvaluator $assertions,
        private ProposeChangeTool $proposeTool,
        private ?User $user = null,
    ) {}

    public function name(): string
    {
        return 'verify_workflow';
    }

    public function description(): string
    {
        return 'Dry-run a workflow to prove it works before the user relies on it: every external AND internal write is SIMULATED (never applied), then a closed set of assertions is checked. Returns {passed, attempt, stop_repairing, assertions, simulated_writes, run}. Call it after building or changing a workflow. If passed=false, fix the workflow with propose_change and verify again — but STOP and explain to the user with what you tried once stop_repairing=true (you have hit the repair limit or the same failure keeps recurring).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow id (wkf_...) to verify, from the current manifest.')
                ->required(),
            'trigger_payload' => $schema->object()
                ->description('Optional sample trigger payload to seed the run (e.g. the record that fired the trigger).'),
            'assertions' => $schema->array()
                ->description('Optional custom assertions from the closed set. Omit to use the default checks (every step completes, no external write applied).'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $workflowId = trim((string) ($args['workflow_id'] ?? ''));

        if ($workflowId === '') {
            return $this->error('workflow_id is required.');
        }

        $manifest = $this->proposeTool->currentManifest();
        if (! is_array($manifest)) {
            return $this->error('This app has no manifest yet — build the workflow before verifying it.');
        }

        $workflow = collect($manifest['workflows'] ?? [])
            ->first(fn (array $w): bool => ($w['id'] ?? null) === $workflowId);

        if (! is_array($workflow)) {
            return $this->error("Workflow '{$workflowId}' is not in the current manifest.");
        }

        $attempt = ($this->attempts[$workflowId] ?? 0) + 1;
        $this->attempts[$workflowId] = $attempt;

        $triggerType = $workflow['trigger']['type'] ?? 'manual';
        $payload = is_array($args['trigger_payload'] ?? null) ? $args['trigger_payload'] : [];

        $run = $this->engine->run($this->app, $manifest, $workflow, $triggerType, $payload, $this->user, dryRun: true);
        $run->load(['steps' => fn ($q) => $q->orderBy('sequence_index')]);

        $assertions = $args['assertions'] ?? null;
        if (! is_array($assertions) || $assertions === []) {
            $assertions = $this->assertions->defaultAssertions($workflow);
        }

        $results = $this->assertions->evaluate($run, $assertions);
        $passed = $run->status === 'completed' && collect($results)->every(fn (array $r): bool => $r['passed']);

        $signature = $this->failureSignature($run, $results, $passed);
        $repeated = $signature !== '' && ($this->lastSignature[$workflowId] ?? null) === $signature;
        $this->lastSignature[$workflowId] = $signature;

        $stopRepairing = ! $passed && ($attempt >= self::MAX_ATTEMPTS || $repeated);

        return json_encode([
            'passed' => $passed,
            'attempt' => $attempt,
            'max_attempts' => self::MAX_ATTEMPTS,
            'stop_repairing' => $stopRepairing,
            'stop_reason' => $stopRepairing
                ? ($repeated
                    ? 'The same failure recurred after a fix — stop patching and explain it to the user.'
                    : 'Reached the repair attempt limit — stop patching and explain what you tried to the user.')
                : null,
            'assertions' => $results,
            'simulated_writes' => $this->simulatedWrites($run),
            'run' => [
                'status' => $run->status,
                'error' => $run->error,
                'steps' => $run->steps->map(fn ($s): array => [
                    'step_id' => $s->step_id,
                    'step_type' => $s->step_type,
                    'status' => $s->status,
                    'error' => $s->error,
                ])->all(),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * A stable fingerprint of *why* a run failed — the run status/error plus the
     * set of failed assertions and per-step errors. Empty when the run passed.
     * Two consecutive non-empty matches mean a patch changed nothing material
     * (oscillation), so the loop must stop rather than burn another attempt.
     *
     * @param  list<array{type: string, label: string, passed: bool, detail: string}>  $results
     */
    private function failureSignature(WorkflowRun $run, array $results, bool $passed): string
    {
        if ($passed) {
            return '';
        }

        $failedAssertions = collect($results)
            ->reject(fn (array $r): bool => $r['passed'])
            ->map(fn (array $r): string => $r['type'].':'.$r['label'])
            ->sort()
            ->values()
            ->all();

        $stepErrors = $run->steps
            ->filter(fn ($s): bool => $s->error !== null)
            ->map(fn ($s): string => $s->step_id.':'.$s->error)
            ->sort()
            ->values()
            ->all();

        return md5((string) json_encode([$run->status, $run->error, $failedAssertions, $stepErrors]));
    }

    /**
     * @return list<array{step_id: string, step_type: string, effect: string, preview: mixed}>
     */
    private function simulatedWrites(WorkflowRun $run): array
    {
        return $run->steps
            ->filter(fn ($s): bool => ($s->output['simulated'] ?? false) === true)
            ->map(fn ($s): array => [
                'step_id' => $s->step_id,
                'step_type' => $s->step_type,
                'effect' => $s->output['effect'] ?? 'write',
                'preview' => $s->output['proposal']['preview'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function error(string $message): string
    {
        return json_encode(['passed' => false, 'error' => $message], JSON_THROW_ON_ERROR);
    }
}
