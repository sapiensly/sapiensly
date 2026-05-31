<?php

namespace App\Services\Workflows;

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\AiProviderService;
use App\Services\Records\ExpressionResolver;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;
use App\Services\Records\SafeExpressionEvaluator;
use App\Services\Security\Ssrf\SafeHttpClient;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Enums\Lab;

/**
 * Executes a manifest workflow inline (no queue for MVP). Iterates the step
 * tree, persists a WorkflowRun + one WorkflowStepRun per step, resolves
 * expressions against a context built from trigger payload + variables +
 * step outputs.
 *
 * Step handlers live on this class as private methods keyed by `step_type`.
 * Failures bubble up through StepFailedException and mark the run failed —
 * subsequent steps are not executed unless the workflow declares an
 * error_handler (deferred to a later phase).
 */
class WorkflowEngine
{
    private const DEFAULT_AI_MODEL = 'claude-sonnet-4-20250514';

    /** Safety cap so a runaway `items` array can't create unbounded records. */
    private const MAX_FOREACH_ITERATIONS = 1000;

    public function __construct(
        private RecordWriteService $writes,
        private RecordQueryService $queries,
        private AiProviderService $aiProviders,
        private ExpressionResolver $expressions,
        private SafeExpressionEvaluator $safe,
        private ScriptRunner $scripts,
        private SafeHttpClient $safeHttp,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $workflow
     * @param  array<string, mixed>  $triggerPayload
     */
    public function run(
        App $app,
        array $manifest,
        array $workflow,
        string $triggerType,
        array $triggerPayload = [],
        ?User $user = null,
    ): WorkflowRun {
        $run = WorkflowRun::create([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'workflow_id' => $workflow['id'],
            'trigger_type' => $triggerType,
            'trigger_payload' => $triggerPayload,
            'status' => 'running',
            'variables' => [],
            'triggered_by_user_id' => $user?->id,
            'started_at' => now(),
        ]);

        $context = [
            'trigger' => $triggerPayload,
            'vars' => [],
            'steps' => [],
            'current_user' => $user ? ['id' => $user->id, 'email' => $user->email] : null,
            'params' => [],
            'form' => [],
        ];

        try {
            $context = $this->executeSteps($run, $workflow['steps'] ?? [], $context, $app, $manifest, $user);

            $run->update([
                'status' => 'completed',
                'variables' => $context['vars'],
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Workflow run failed', [
                'workflow_id' => $workflow['id'],
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
            $run->update([
                'status' => 'failed',
                'variables' => $context['vars'] ?? [],
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $run->refresh();
    }

    /**
     * Iterate a sequence of steps, persisting one StepRun each. Returns the
     * updated context (steps[<id>].output and vars carry over across steps).
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function executeSteps(WorkflowRun $run, array $steps, array $context, App $app, array $manifest, ?User $user, int $startIndex = 0): array
    {
        foreach ($steps as $i => $step) {
            $sequenceIndex = $startIndex + $i;

            $stepRun = WorkflowStepRun::create([
                'run_id' => $run->id,
                'step_id' => $step['id'],
                'step_type' => $step['type'],
                'status' => 'running',
                'sequence_index' => $sequenceIndex,
                'started_at' => now(),
            ]);

            if (isset($step['skip_if']) && $this->resolveToBool($step['skip_if'], $context)) {
                $stepRun->update(['status' => 'skipped', 'finished_at' => now()]);

                continue;
            }

            try {
                $output = $this->dispatch($step, $context, $app, $manifest, $user, $run);
                $stepRun->update([
                    'status' => 'completed',
                    'output' => is_array($output) ? $output : ['value' => $output],
                    'finished_at' => now(),
                ]);

                $context['steps'][$step['id']] = ['output' => is_array($output) ? $output : ['value' => $output]];

                if (isset($step['output_variable'])) {
                    $context['vars'][$step['output_variable']] = $output;
                }
            } catch (\Throwable $e) {
                $stepRun->update([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'finished_at' => now(),
                ]);
                throw new StepFailedException(
                    "Step '{$step['id']}' ({$step['type']}) failed: {$e->getMessage()}",
                    $e,
                );
            }
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $manifest
     */
    private function dispatch(array $step, array &$context, App $app, array $manifest, ?User $user, WorkflowRun $run): mixed
    {
        return match ($step['type']) {
            'log' => $this->handleLog($step, $context),
            'set_variable' => $this->handleSetVariable($step, $context),
            'record.create' => $this->handleRecordCreate($step, $context, $app, $manifest, $user),
            'record.update' => $this->handleRecordUpdate($step, $context, $app, $manifest, $user),
            'record.delete' => $this->handleRecordDelete($step, $context, $app),
            'record.query' => $this->handleRecordQuery($step, $context, $app, $manifest),
            'branch' => $this->handleBranch($step, $context, $app, $manifest, $user, $run),
            'foreach' => $this->handleForeach($step, $context, $app, $manifest, $user, $run),
            'ai.complete' => $this->handleAiComplete($step, $context, $user),
            'http.request' => $this->handleHttpRequest($step, $context),
            'script.run' => $this->handleScriptRun($step, $context),
            default => throw new StepFailedException("Unknown step type '{$step['type']}'"),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{text: string, model: string}
     */
    private function handleAiComplete(array $step, array $context, ?User $user): array
    {
        $systemPrompt = isset($step['system_prompt'])
            ? (string) $this->expressions->resolve((string) $step['system_prompt'], $context)
            : '';
        $userPrompt = (string) $this->expressions->resolve((string) $step['user_prompt'], $context);
        $model = $step['model'] ?? self::DEFAULT_AI_MODEL;

        if ($user !== null) {
            $this->aiProviders->applyRuntimeConfig($user);
            $provider = $this->aiProviders->resolveProvider($model, $user);
        } else {
            $provider = Lab::Anthropic;
        }

        $sdkAgent = new AnonymousAgent($systemPrompt, [], []);
        $response = $sdkAgent->prompt($userPrompt, provider: $provider, model: $model);

        return [
            'text' => $response->text ?? '',
            'model' => $model,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{status: int, body: mixed, headers: array<string, mixed>}
     */
    private function handleHttpRequest(array $step, array $context): array
    {
        $url = (string) $this->expressions->resolve((string) $step['url'], $context);
        $method = strtoupper((string) $step['method']);
        $timeout = (int) ($step['timeout_seconds'] ?? 30);

        $headers = [];
        foreach ($step['headers'] ?? [] as $k => $v) {
            $headers[$k] = is_string($v) ? (string) $this->expressions->resolve($v, $context) : $v;
        }
        $query = [];
        foreach ($step['query'] ?? [] as $k => $v) {
            $query[$k] = is_string($v) ? (string) $this->expressions->resolve($v, $context) : $v;
        }

        $body = $step['body'] ?? null;
        if (is_string($body)) {
            $body = $this->expressions->resolve($body, $context);
        } elseif (is_array($body)) {
            $body = $this->resolveValuesMap($body, $context);
        }

        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new StepFailedException("Unsupported HTTP method '{$method}'");
        }

        // Route every user-controlled outbound call through the SSRF guard:
        // it resolves DNS, rejects internal/reserved IPs, pins the connection
        // to the validated address (anti-rebinding) and follows redirects
        // re-validating each hop. A blocked destination throws and the step
        // (and run) fails cleanly via the surrounding catch.
        $options = ['headers' => $headers, 'timeout' => $timeout];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if (is_array($body)) {
            $options['json'] = $body;
        } elseif ($body !== null) {
            $options['body'] = $body;
        }

        $response = $this->safeHttp->request($method, $url, $options);

        $decoded = $response->json();

        return [
            'status' => $response->status(),
            'body' => $decoded ?? $response->body(),
            'headers' => $response->headers(),
        ];
    }

    /**
     * Run a user-authored JavaScript snippet in the QuickJS sandbox. The step's
     * `input` map is resolved against the workflow context (so the script sees
     * concrete values, never raw tokens) and passed as the `input` argument;
     * the script's return value becomes this step's output.
     *
     * @param  array<string, mixed>  $context
     */
    private function handleScriptRun(array $step, array $context): mixed
    {
        $input = $this->resolveValuesMap($step['input'] ?? [], $context);
        $timeoutMs = (int) ($step['timeout_ms'] ?? 2000);

        return $this->scripts->run((string) ($step['code'] ?? ''), $input, $timeoutMs);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function handleLog(array $step, array $context): array
    {
        $message = (string) $this->expressions->resolve((string) $step['message'], $context);
        $level = $step['level'] ?? 'info';
        Log::log($level, "[workflow] {$message}");

        return ['logged' => true, 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function handleSetVariable(array $step, array &$context): array
    {
        $value = $this->expressions->resolve((string) $step['value'], $context);
        $context['vars'][$step['variable']] = $value;

        return ['variable' => $step['variable'], 'value' => $value];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $manifest
     */
    private function handleRecordCreate(array $step, array $context, App $app, array $manifest, ?User $user): array
    {
        $values = $this->resolveValuesMap($step['values'] ?? [], $context);
        $record = $this->writes->create($app, $manifest, $step['object_id'], $values, $user);

        return ['record_id' => $record->id, 'data' => $record->data];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $manifest
     */
    private function handleRecordUpdate(array $step, array $context, App $app, array $manifest, ?User $user): array
    {
        $recordId = (string) $this->expressions->resolve((string) $step['record_id_expression'], $context);
        $record = Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $step['object_id'])
            ->find($recordId);
        if ($record === null) {
            throw new StepFailedException("Record '{$recordId}' not found.");
        }
        $values = $this->resolveValuesMap($step['values'] ?? [], $context);
        $updated = $this->writes->update($app, $manifest, $record, $values, $user);

        return ['record_id' => $updated->id, 'data' => $updated->data];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function handleRecordDelete(array $step, array $context, App $app): array
    {
        $recordId = (string) $this->expressions->resolve((string) $step['record_id_expression'], $context);
        $record = Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $step['object_id'])
            ->find($recordId);
        if ($record === null) {
            throw new StepFailedException("Record '{$recordId}' not found.");
        }
        $this->writes->delete($record);

        return ['record_id' => $recordId];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $manifest
     */
    private function handleRecordQuery(array $step, array $context, App $app, array $manifest): array
    {
        $query = ['object_id' => $step['object_id']];
        if (isset($step['filter'])) {
            $query['filter'] = $step['filter'];
        }
        if (isset($step['limit'])) {
            $query['limit'] = $step['limit'];
        }
        $records = $this->queries->query($app, $query, $manifest, $context);

        return [
            'count' => $records->count(),
            'rows' => $records->map(fn ($r) => ['id' => $r->id, 'data' => $r->data])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $manifest
     */
    private function handleBranch(array $step, array &$context, App $app, array $manifest, ?User $user, WorkflowRun $run): array
    {
        foreach ($step['cases'] ?? [] as $i => $case) {
            if ($this->resolveToBool((string) $case['condition'], $context)) {
                $context = $this->executeSteps($run, $case['steps'] ?? [], $context, $app, $manifest, $user);

                return ['matched' => $i];
            }
        }
        if (isset($step['default_steps'])) {
            $context = $this->executeSteps($run, $step['default_steps'], $context, $app, $manifest, $user);

            return ['matched' => 'default'];
        }

        return ['matched' => null];
    }

    /**
     * Iterate the array produced by the `items` expression and run the
     * sub-steps once per element, exposing the element (and optional index) as
     * workflow variables so inner steps like record.create can reference them
     * via {{vars.<item_variable>}}. This is the fan-out primitive: e.g. a
     * script.run computes a list, then a foreach creates one record per entry.
     *
     * @param  array<string, mixed>  $context
     */
    private function handleForeach(array $step, array &$context, App $app, array $manifest, ?User $user, WorkflowRun $run): array
    {
        $items = $this->expressions->resolve((string) ($step['items'] ?? ''), $context);
        $items = is_array($items) ? array_values($items) : [];

        $itemVar = is_string($step['item_variable'] ?? null) ? $step['item_variable'] : 'item';
        $indexVar = is_string($step['index_variable'] ?? null) ? $step['index_variable'] : null;

        $truncated = count($items) > self::MAX_FOREACH_ITERATIONS;
        if ($truncated) {
            Log::warning('foreach step truncated to the iteration cap', [
                'cap' => self::MAX_FOREACH_ITERATIONS,
                'requested' => count($items),
            ]);
            $items = array_slice($items, 0, self::MAX_FOREACH_ITERATIONS);
        }

        foreach ($items as $index => $element) {
            $context['vars'][$itemVar] = $element;
            if ($indexVar !== null) {
                $context['vars'][$indexVar] = $index;
            }
            $context = $this->executeSteps($run, $step['steps'] ?? [], $context, $app, $manifest, $user);
        }

        return ['iterations' => count($items), 'truncated' => $truncated];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveValuesMap(array $values, array $context): array
    {
        $resolved = [];
        foreach ($values as $slug => $value) {
            $resolved[$slug] = is_string($value)
                ? $this->expressions->resolve($value, $context)
                : $value;
        }

        return $resolved;
    }

    /**
     * Resolve a branch condition to a boolean. Conditions are full boolean
     * expressions — `{{trigger.record.data.estado}} == "activo"`,
     * `{{vars.total}} > 100 && {{vars.activo}}`, `{{vars.tipo}} != "x"` — with
     * the `{{ }}` delimiters stripped so the context roots are bound as
     * variables and handed to the sandboxed engine. Falls back to the legacy
     * shapes for anything the grammar can't parse.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveToBool(string $expression, array $context): bool
    {
        $trimmed = trim($expression);

        if ($trimmed === '') {
            return false;
        }

        $bare = (string) preg_replace('/\{\{\s*([^}]+?)\s*\}\}/', '$1', $trimmed);

        try {
            return (bool) $this->safe->evaluate($bare, $context);
        } catch (\Throwable) {
            return $this->resolveToBoolLegacy($trimmed, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveToBoolLegacy(string $trimmed, array $context): bool
    {
        if ($trimmed === 'true' || $trimmed === '1') {
            return true;
        }
        if ($trimmed === 'false' || $trimmed === '0') {
            return false;
        }

        // Simple "<expr> == <literal>" matcher.
        if (preg_match('/^(\{\{.+?\}\})\s*==\s*(.+)$/', $trimmed, $m)) {
            $left = $this->expressions->resolve($m[1], $context);
            $right = trim($m[2], "\"' ");

            return (string) $left === $right;
        }

        return (bool) $this->expressions->resolve($trimmed, $context);
    }
}
