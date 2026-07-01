<?php

namespace App\Services\Workflows;

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\App;
use App\Models\IntegrationUserToken;
use App\Models\Message;
use App\Models\Record;
use App\Models\Tool;
use App\Models\User;
use App\Models\WorkflowProposal;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Services\Ai\AiSpendGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\AiProviderService;
use App\Services\Connectors\ConnectorCallGate;
use App\Services\LLMService;
use App\Services\Records\ExpressionResolver;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;
use App\Services\Records\SafeExpressionEvaluator;
use App\Services\Security\Ssrf\SafeHttpClient;
use App\Services\ToolExecutionService;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;

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

    /** When true, writes are simulated rather than applied (verification pass). */
    private bool $dryRun = false;

    /**
     * When true (the default for real runs), a non-`safe` connector write halts
     * the run and emits a proposal instead of mutating (propose-don't-mutate).
     * Disabled only when executing an already-approved proposal.
     */
    private bool $gateApprovals = true;

    public function __construct(
        private RecordWriteService $writes,
        private RecordQueryService $queries,
        private AiProviderService $aiProviders,
        private ExpressionResolver $expressions,
        private SafeExpressionEvaluator $safe,
        private ScriptRunner $scripts,
        private SafeHttpClient $safeHttp,
        private ToolExecutionService $toolExecution,
        private ConnectorCallGate $connectorGate,
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
        bool $dryRun = false,
    ): WorkflowRun {
        // Verification pass: simulate every write (internal records + external
        // connector/http calls), never apply them, and run only side-effect-free
        // steps for real. Reset per run so a reused engine can't leak the flag.
        $this->dryRun = $dryRun;
        $this->gateApprovals = true;

        $run = WorkflowRun::create([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'workflow_id' => $workflow['id'],
            'trigger_type' => $triggerType,
            'trigger_payload' => $triggerPayload,
            'status' => 'running',
            'dry_run' => $dryRun,
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
        } catch (WorkflowAwaitingApprovalException $e) {
            // A gated write halted the run. Record the proposal and stop —
            // emit-then-stop: an approver executes it later (FR-5.3/9.3).
            WorkflowProposal::create([
                'organization_id' => $app->organization_id,
                'app_id' => $app->id,
                'workflow_id' => $workflow['id'],
                'run_id' => $run->id,
                'step_id' => $e->proposal['step_id'],
                'effect' => $e->proposal['effect'],
                'action' => $e->proposal['action'],
                'preview' => $e->proposal['preview'],
                'status' => 'pending',
            ]);
            $run->update([
                'status' => 'awaiting_approval',
                'variables' => $context['vars'] ?? [],
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
     * Execute a single previously-approved action for real, bypassing the gate.
     * The action's inputs were already resolved to literals at proposal time, so
     * re-resolving them here is a no-op. Used by the approval flow — never runs a
     * whole workflow (emit-then-stop has no resume).
     *
     * @param  array{type: string, tool_id?: string, inputs?: array<string, mixed>}  $action
     * @return array<string, mixed>
     */
    public function executeApprovedAction(App $app, array $action, ?User $user = null): array
    {
        $this->dryRun = false;
        $this->gateApprovals = false;

        return match ($action['type'] ?? null) {
            'connector.call' => $this->handleConnectorCall([
                'id' => 'approved_action',
                'type' => 'connector.call',
                'tool_id' => $action['tool_id'] ?? '',
                'inputs' => $action['inputs'] ?? [],
            ], ['trigger' => [], 'vars' => [], 'steps' => []], $app, $user),
            default => throw new StepFailedException("Cannot execute approved action of type '".($action['type'] ?? 'null')."'"),
        };
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
            } catch (WorkflowAwaitingApprovalException $e) {
                // The gated step (or a container around it) pauses, never fails.
                // Only the leaf carries the proposal preview in its output.
                $stepRun->update([
                    'status' => 'awaiting_approval',
                    'output' => ($e->proposal['step_id'] ?? null) === $step['id']
                        ? ['proposal' => $e->proposal]
                        : null,
                    'finished_at' => now(),
                ]);
                throw $e;
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
            'agent.invoke' => $this->handleAgentInvoke($step, $context, $app, $user),
            'http.request' => $this->handleHttpRequest($step, $context),
            'connector.call' => $this->handleConnectorCall($step, $context, $app, $user),
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

        // Don't spend tokens during verification — return a placeholder.
        if ($this->dryRun) {
            return ['text' => '[simulated AI output]', 'model' => $model, 'simulated' => true];
        }

        if ($user !== null) {
            $this->aiProviders->applyRuntimeConfig($user);
            $provider = $this->aiProviders->resolveProvider($model, $user);
        } else {
            $provider = Lab::Anthropic;
        }

        app(AiSpendGuard::class)->assertWithinBudget(
            $user, $user?->organization_id, $model,
        );

        $sdkAgent = new AnonymousAgent($systemPrompt, [], []);
        $response = $sdkAgent->prompt($userPrompt, provider: $provider, model: $model, timeout: (int) config('ai.request_timeout', 180));

        app(AiUsageRecorder::class)->record(
            'workflow', $model, $user, $user?->organization_id, $response->usage ?? null,
        );

        return [
            'text' => $response->text ?? '',
            'model' => $model,
        ];
    }

    /**
     * Invoke a configured agent — unlike ai.complete (a bare prompt), this runs
     * the agent with its own model, instructions, knowledge bases (RAG) and tools.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $manifest
     * @return array{text: string, agent_id: string, knowledge_bases?: array<int, string>}
     */
    private function handleAgentInvoke(array $step, array $context, App $app, ?User $user): array
    {
        $agentId = (string) $step['agent_id'];
        $message = (string) $this->expressions->resolve((string) $step['message'], $context);

        if ($this->dryRun) {
            return ['text' => '[simulated agent output]', 'agent_id' => $agentId, 'simulated' => true];
        }

        // Resolve within the run's owner context when present, else within the
        // app's organization (scheduled/webhook runs without a user).
        $agent = $user !== null
            ? Agent::query()->forAccountContext($user)->find($agentId)
            : Agent::query()->where('organization_id', $app->organization_id)->find($agentId);

        if ($agent === null) {
            throw new StepFailedException("agent.invoke references unknown agent '{$agentId}' — use list_agents for a real id.");
        }

        app(AiSpendGuard::class)->assertWithinBudget(
            $user, $user?->organization_id ?? $app->organization_id, $agent->model,
        );

        $userMessage = new Message(['role' => MessageRole::User, 'content' => $message]);
        $result = app(LLMService::class)->setContext($user)->chatWithKnowledgeAndTools($agent, [$userMessage]);

        /** @var AgentResponse $response */
        $response = $result['response'];

        return [
            'text' => $response->text ?? '',
            'agent_id' => $agent->id,
            'knowledge_bases' => array_column($result['knowledge_bases'] ?? [], 'name'),
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

        // Don't make the external call during verification — simulate it.
        if ($this->dryRun) {
            return ['status' => 0, 'body' => null, 'headers' => [], 'simulated' => true, 'effect' => 'write'];
        }

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
     * Invoke a typed connector action — a configured REST/GraphQL/database Tool
     * — by id. Inputs are resolved against the workflow context and the call
     * runs through the shared ToolExecutionService, which decrypts the tool
     * config, validates it and routes outbound calls through the SSRF guard.
     *
     * Phase 1 supports tools whose auth is self-contained (none / bearer /
     * api_key / basic). A per-user OAuth2 connector that the acting user has
     * not authorized fails with an explicit "authorize this integration" error
     * (FR-5.1) rather than a silent skip; wiring the per-user token into the
     * execution path is part of the provisioning/authorization phase.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $context
     * @return array{data: mixed, effect: string, status: string}
     */
    private function handleConnectorCall(array $step, array $context, App $app, ?User $user): array
    {
        $toolId = (string) ($step['tool_id'] ?? '');

        $tool = $this->resolveConnectorTool($toolId, $app, $user);

        if ($tool === null) {
            throw new StepFailedException(
                "connector.call references unknown or inaccessible tool '{$toolId}'",
            );
        }

        $inputs = [];
        foreach ($step['inputs'] ?? [] as $name => $expression) {
            $inputs[$name] = is_string($expression)
                ? $this->expressions->resolve($expression, $context)
                : $expression;
        }

        $decision = $this->connectorGate->inspect($tool, $this->gateApprovals);
        $contract = $decision->contract;

        // Verification pass: never call the external system. Emit a Proposal
        // preview (what WOULD happen) instead — for writes and reads alike, so
        // the dry-run is hermetic (no live calls, no provider-side effects).
        if ($this->dryRun) {
            return [
                'data' => null,
                'effect' => $contract->effect->value,
                'status' => 'simulated',
                'simulated' => true,
                'proposal' => [
                    'action' => $contract->name,
                    'integration_id' => $contract->integrationId,
                    'inputs' => $inputs,
                    'preview' => $contract->blastRadius,
                ],
            ];
        }

        // Propose-don't-mutate: a non-`safe` write to an external system of
        // record halts the run and emits a proposal instead of executing
        // (FR-5.3/9.3). `safe`-marked writes and all reads run straight through.
        if ($decision->mustGate) {
            throw new WorkflowAwaitingApprovalException([
                'step_id' => (string) ($step['id'] ?? 'connector.call'),
                'effect' => $contract->effect->value,
                'action' => [
                    'type' => 'connector.call',
                    'tool_id' => $tool->id,
                    'inputs' => $inputs,
                ],
                'preview' => $contract->blastRadius,
            ]);
        }

        $this->assertConnectorAuthorized($tool, $user);

        $result = $this->toolExecution->execute($tool, $inputs);

        if (! $result->success) {
            throw new StepFailedException(
                "connector.call to '{$tool->name}' failed: {$result->error}",
            );
        }

        return [
            'data' => $result->data,
            'effect' => $contract->effect->value,
            'status' => 'ok',
        ];
    }

    /**
     * Resolve a connector Tool scoped to the run's tenant: by the acting user's
     * account context when present, otherwise restricted to the app's
     * organization. Prevents a manifest from reaching another tenant's tools.
     */
    private function resolveConnectorTool(string $toolId, App $app, ?User $user): ?Tool
    {
        if ($toolId === '') {
            return null;
        }

        $query = Tool::query();

        if ($user !== null) {
            $query->forAccountContext($user);
        } else {
            $query->where('organization_id', $app->organization_id);
        }

        return $query->whereKey($toolId)->first();
    }

    /**
     * Fail closed for per-user OAuth2 connectors the acting user has not
     * authorized — an explicit, legible error, never a silent skip (FR-5.1).
     */
    private function assertConnectorAuthorized(Tool $tool, ?User $user): void
    {
        $config = $tool->config ?? [];
        $integrationId = $config['integration_id'] ?? null;

        if (($config['auth_type'] ?? null) !== 'oauth2' || $integrationId === null) {
            return;
        }

        $authorized = $user !== null && IntegrationUserToken::query()
            ->where('user_id', $user->id)
            ->where('integration_id', $integrationId)
            ->get()
            ->contains(fn (IntegrationUserToken $token): bool => $token->isAuthorized());

        if (! $authorized) {
            throw new StepFailedException(
                "connector.call to '{$tool->name}' needs authorization — authorize the integration before running this flow.",
            );
        }
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

        if ($this->dryRun) {
            return ['record_id' => 'dry_run', 'data' => $values, 'simulated' => true, 'effect' => 'write'];
        }

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

        if ($this->dryRun) {
            return [
                'record_id' => $recordId,
                'data' => $this->resolveValuesMap($step['values'] ?? [], $context),
                'simulated' => true,
                'effect' => 'write',
            ];
        }

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

        if ($this->dryRun) {
            return ['record_id' => $recordId, 'simulated' => true, 'effect' => 'write'];
        }

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
