<?php

namespace App\Services\Builder;

use App\Ai\BuilderAgent;
use App\Ai\Tools\Builder\AddCrudPageTool;
use App\Ai\Tools\Builder\AddDashboardPageTool;
use App\Ai\Tools\Builder\AddDetailPageTool;
use App\Ai\Tools\Builder\CreateIntegrationTool;
use App\Ai\Tools\Builder\DeleteBlockByIdTool;
use App\Ai\Tools\Builder\DiscoverIntegrationTool;
use App\Ai\Tools\Builder\FrameworkReferenceTool;
use App\Ai\Tools\Builder\GeneratePaletteTool;
use App\Ai\Tools\Builder\InspectRecordsTool;
use App\Ai\Tools\Builder\ListAvailableActionsTool;
use App\Ai\Tools\Builder\ListAvailableComponentsTool;
use App\Ai\Tools\Builder\ListAvailableFieldTypesTool;
use App\Ai\Tools\Builder\ListAvailableIconsTool;
use App\Ai\Tools\Builder\ListAvailableIntegrationsTool;
use App\Ai\Tools\Builder\ListAvailableStepsTool;
use App\Ai\Tools\Builder\ListAvailableTriggersTool;
use App\Ai\Tools\Builder\ListConnectorActionsTool;
use App\Ai\Tools\Builder\ListDashboardBlueprintsTool;
use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Ai\Tools\Builder\PrepareDashboardTool;
use App\Ai\Tools\Builder\ProfileObjectTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Ai\Tools\Builder\ProposePlanTool;
use App\Ai\Tools\Builder\ReadManifestTool;
use App\Ai\Tools\Builder\SampleEndpointTool;
use App\Ai\Tools\Builder\SampleMcpToolTool;
use App\Ai\Tools\Builder\ScaffoldAppTool;
use App\Ai\Tools\Builder\SeedRecordsTool;
use App\Ai\Tools\Builder\SetBuildPlanTool;
use App\Ai\Tools\Builder\SimulateQueryTool;
use App\Ai\Tools\Builder\TargetPlanStepsTool;
use App\Ai\Tools\Builder\TestIntegrationConnectionTool;
use App\Ai\Tools\Builder\ValidateManifestTool;
use App\Ai\Tools\Builder\VerifyWorkflowTool;
use App\Ai\Tools\Platform\McpBridgeTool;
use App\Ai\Tools\RuntimeToolFactory;
use App\Events\Builder\BuilderActivity;
use App\Events\Builder\BuilderStreamChunk;
use App\Events\Builder\BuilderStreamComplete;
use App\Events\Builder\BuilderStreamError;
use App\Events\Builder\BuilderTurnQueued;
use App\Jobs\RunBuilderAiJob;
use App\Mcp\Tools\Account\CurrentDatetimeTool;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiSpendGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\AiProviderService;
use App\Services\Builder\Integrations\IntegrationAuthoring;
use App\Services\Connectors\ConnectorActionResolver;
use App\Services\Integrations\IntegrationCaller;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;
use App\Services\Storage\TenantStorage;
use App\Services\Tools\McpClient;
use App\Services\Workflows\WorkflowAssertionEvaluator;
use App\Services\Workflows\WorkflowEngine;
use App\Support\Branding\OrganizationBrand;
use App\Support\CurrentDateTime;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\Error as StreamingError;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use RuntimeException;

/**
 * Orchestrates a Claude conversation that edits an App manifest via tool use.
 * The model can read the manifest, list catalogs, validate drafts, and propose
 * RFC 6902 patches. Proposals are persisted on the assistant message but
 * NOT applied — the user approves them from the UI, which then calls
 * approveProposal() to materialize the new manifest version.
 */
class BuilderAiService
{
    /**
     * Model used specifically for "Pedir revisión visual" turns. Sonnet 4.5
     * follows scope instructions much more reliably than Haiku — relevant
     * here because visual review has a hard scope limit ("don't add new
     * features, just review what's there") that Haiku tended to ignore
     * once it saw an "incomplete" looking page in the screenshot. Cost
     * matters less here because visual review is invoked by an explicit
     * button click, not by every chat turn.
     */
    public const VISUAL_REVIEW_MODEL = 'claude-sonnet-4-5-20250929';

    private const MAX_HISTORY_MESSAGES = 30;

    /**
     * Hard ceiling on consecutive auto-continued turns in autonomous mode — a
     * runaway/token backstop. The loop normally stops earlier (plan complete or
     * a turn that didn't advance the plan).
     */
    public const AUTONOMOUS_MAX_TURNS = 8;

    /** The default builder model id, exposed so the UI can pre-select it in the model picker. */
    public static function defaultModel(): string
    {
        return app(AiDefaults::class)->model('builder');
    }

    public function __construct(
        private AppManifestService $manifestService,
        private ManifestValidator $validator,
        private AiProviderService $providers,
        private RecordQueryService $records,
        private RecordWriteService $writer,
        private TenantStorage $tenantStorage,
        private AiDefaults $aiDefaults,
        private IntegrationAuthoring $integrationAuthoring,
    ) {}

    public function startConversation(App $app, User $user): BuilderConversation
    {
        return BuilderConversation::create([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    /**
     * Send a user message, run Claude with the builder tools, persist both
     * the user turn and the assistant turn (with any proposed patch).
     */
    public function sendMessage(BuilderConversation $conversation, string $userText): BuilderMessage
    {
        // Claude tool-use loops with 7 tools can take 60-120s. The default
        // PHP-FPM / nginx timeouts will kill the request — disable PHP's own
        // ceiling so at least the worker survives long enough to log results.
        set_time_limit(0);

        BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userText,
            'status' => 'none',
        ]);

        $app = $conversation->app;

        Log::info('Builder AI sendMessage starting', [
            'conversation_id' => $conversation->id,
            'app_id' => $app->id,
            'user_text_length' => strlen($userText),
        ]);

        $proposeTool = new ProposeChangeTool($app, $this->manifestService, $this->validator);
        $planTool = new ProposePlanTool;
        $createIntegrationTool = new CreateIntegrationTool($this->integrationAuthoring, $conversation->user);

        $tools = [
            new ReadManifestTool($app, $this->manifestService, $proposeTool),
            new FrameworkReferenceTool,
            new ListAvailableComponentsTool,
            new ListDashboardBlueprintsTool,
            new PlanDashboardTool,
            new ListAvailableIconsTool,
            new GeneratePaletteTool($app->organization?->brandbook()),
            new ListAvailableFieldTypesTool,
            new ListAvailableActionsTool,
            new ListAvailableTriggersTool,
            new ListAvailableStepsTool,
            new InspectRecordsTool($app),
            new ProfileObjectTool($app, $this->manifestService, $this->records, $proposeTool),
            new PrepareDashboardTool($app, $this->manifestService, $this->records, $proposeTool),
            new SimulateQueryTool($app, $this->manifestService, $this->records, $proposeTool),
            new ValidateManifestTool($this->validator),
            $proposeTool,
            $planTool,
            new ScaffoldAppTool($app, $this->manifestService, $proposeTool, app(AppScaffolder::class)),
            new AddCrudPageTool($app, $this->manifestService, $proposeTool, app(AppScaffolder::class)),
            new AddDetailPageTool($app, $this->manifestService, $proposeTool, app(AppScaffolder::class)),
            new AddDashboardPageTool($app, $this->manifestService, $proposeTool, app(AppScaffolder::class)),
            new SetBuildPlanTool($conversation),
            new TargetPlanStepsTool($conversation),
            new DeleteBlockByIdTool($app, $this->manifestService, $proposeTool),
            new SeedRecordsTool($app, $this->manifestService, $this->writer, $conversation->user, $proposeTool),
            new ListAvailableIntegrationsTool($conversation->user),
            new ListConnectorActionsTool(app(ConnectorActionResolver::class), $conversation->user),
            new VerifyWorkflowTool($app, app(WorkflowEngine::class), app(WorkflowAssertionEvaluator::class), $proposeTool, $conversation->user),
            new DiscoverIntegrationTool($this->integrationAuthoring),
            $createIntegrationTool,
            new TestIntegrationConnectionTool($this->integrationAuthoring, $conversation->user),
            new SampleEndpointTool(app(IntegrationCaller::class), $conversation->user),
            new SampleMcpToolTool(app(McpClient::class), $conversation->user),
            // The clock every model must ground time-relative reasoning on
            // (dashboards, date filters, "last N days"). Not in the Builder's
            // PlatformToolsFactory path, so bridged in explicitly.
            RuntimeToolFactory::named('current_datetime', new McpBridgeTool(CurrentDatetimeTool::class, $conversation->user)),
        ];

        $history = $this->buildHistory($conversation);
        $prompt = array_pop($history); // the user turn just stored

        $systemPrompt = $this->systemPrompt($app);
        $sdkAgent = new BuilderAgent(
            instructions: $systemPrompt,
            messages: $history,
            tools: $tools,
        );
        // Cache the static system + tool-definition prefix (re-sent every turn)
        // as an Anthropic prompt-cache breakpoint, so it bills at ~0.1x after
        // the first turn of a conversation.
        if (config('ai.prompt_caching.enabled')) {
            $sdkAgent->withCacheableSystem($systemPrompt);
        }

        $startedAt = microtime(true);

        try {
            $user = $conversation->user;
            if ($user !== null) {
                $this->providers->applyRuntimeConfig($user);
            }

            $promptText = $prompt instanceof UserMessage ? ($prompt->content ?? '') : $userText;
            $promptText = $this->withPlanContext($promptText, $conversation);

            // Resolve the builder's primary model; on an LLM/provider error,
            // withFallback re-runs the prompt with the configured fallback.
            $response = $this->aiDefaults->withFallback('builder', function (string $model) use ($sdkAgent, $promptText, $user, $conversation) {
                $provider = $user !== null
                    ? ($this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic)
                    : Lab::Anthropic;

                Log::info('Builder AI calling provider', [
                    'conversation_id' => $conversation->id,
                    'provider' => $provider->value,
                    'model' => $model,
                ]);

                $sdkAgent->forModel($model);

                return $sdkAgent->prompt($promptText, provider: $provider, model: $model, timeout: (int) config('ai.request_timeout', 180));
            });
            $assistantText = $response->text ?? '';

            Log::info('Builder AI provider responded', [
                'conversation_id' => $conversation->id,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'response_length' => strlen($assistantText),
                'has_proposal' => $proposeTool->lastProposal() !== null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Builder AI request failed', [
                'conversation_id' => $conversation->id,
                'app_id' => $app->id,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return BuilderMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => 'Sorry — the AI request failed: '.(trim($e->getMessage()) !== '' ? $e->getMessage() : $e::class),
                'status' => 'none',
            ]);
        }

        $proposal = $proposeTool->lastProposal();

        return BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $assistantText,
            'proposed_patch' => $proposal['patch'] ?? null,
            'change_summary' => $proposal['summary'] ?? null,
            'plan' => $planTool->plan(),
            'integration_proposal' => $createIntegrationTool->proposal(),
            'status' => $proposal !== null ? 'pending' : 'none',
        ]);
    }

    /**
     * Run a full synchronous builder turn AND auto-apply its proposal — the same
     * net effect as the in-app streaming path (streamMessage + commitTurn) but
     * without WebSocket streaming, for non-interactive callers (the MCP
     * `continue_builder_conversation` tool). Returns the finalized assistant
     * message: status 'applied' with applied_version_id when a proposal landed,
     * 'none' when the turn was pure chat or the apply failed (the proposal is
     * kept on the message so it can be retried/approved in-app).
     */
    public function sendMessageAndApply(BuilderConversation $conversation, string $userText, ?User $user = null): BuilderMessage
    {
        $message = $this->sendMessage($conversation, $userText);

        if ($message->status !== 'pending' || empty($message->proposed_patch)) {
            // No proposal this turn — release any targeted plan steps back to
            // pending (a pure-chat or phantom turn advances nothing).
            $released = $this->applyPlanProgress($conversation, [
                'status' => $message->status, 'applied_version_id' => null, 'error' => null,
                'change_summary' => $message->change_summary,
            ]);
            if ($released !== []) {
                $message->update(['plan_step_ids' => $released]);
            }

            return $message->refresh();
        }

        $result = $this->commitTurn(
            $conversation->app,
            ['patch' => $message->proposed_patch, 'summary' => $message->change_summary],
            $user,
            (string) $message->content,
        );

        $closedSteps = $this->applyPlanProgress($conversation, $result);

        $message->update([
            'content' => $result['content'],
            'status' => $result['status'],
            'applied_version_id' => $result['applied_version_id'],
            'plan_step_ids' => $closedSteps !== [] ? $closedSteps : null,
        ]);

        return $message->refresh();
    }

    /**
     * Async variant invoked from RunBuilderAiJob. The user message has
     * already been persisted by the controller and a placeholder assistant
     * message is passed in with status='streaming'. We stream Claude's
     * response, broadcast deltas, then finalize the placeholder + broadcast
     * BuilderStreamComplete.
     */
    public function streamMessage(BuilderMessage $placeholder, string $userText, ?string $attachmentPath = null, ?string $attachmentDisk = null, ?string $modelOverride = null, bool $applyProposal = true): BuilderMessage
    {
        set_time_limit(0);

        $conversation = $placeholder->conversation;
        $app = $conversation->app;

        Log::info('Builder AI streamMessage starting', [
            'conversation_id' => $conversation->id,
            'message_id' => $placeholder->id,
            'app_id' => $app->id,
        ]);

        $proposeTool = new ProposeChangeTool($app, $this->manifestService, $this->validator);
        $planTool = new ProposePlanTool;
        $createIntegrationTool = new CreateIntegrationTool($this->integrationAuthoring, $conversation->user);

        // Checkpoint accumulated valid work onto the placeholder after each
        // successful propose_change. The turn runs in a queue worker with a hard
        // 300s wall-clock; if it times out mid-loop the end-of-turn apply never
        // runs, so without this a long turn's valid progress would be discarded
        // and the next "continue" would restart from an empty app.
        // RunBuilderAiJob::failed() applies this checkpoint so the work is banked.
        $proposeTool->onProgress(function (array $proposal) use ($placeholder): void {
            $placeholder->forceFill([
                'proposed_patch' => $proposal['patch'],
                'change_summary' => $proposal['summary'] ?? null,
            ])->save();
        });

        $tools = [
            new ReadManifestTool($app, $this->manifestService, $proposeTool),
            new FrameworkReferenceTool,
            new ListAvailableComponentsTool,
            new ListDashboardBlueprintsTool,
            new PlanDashboardTool,
            new ListAvailableIconsTool,
            new GeneratePaletteTool($app->organization?->brandbook()),
            new ListAvailableFieldTypesTool,
            new ListAvailableActionsTool,
            new ListAvailableTriggersTool,
            new ListAvailableStepsTool,
            new InspectRecordsTool($app),
            new ProfileObjectTool($app, $this->manifestService, $this->records, $proposeTool),
            new PrepareDashboardTool($app, $this->manifestService, $this->records, $proposeTool),
            new SimulateQueryTool($app, $this->manifestService, $this->records, $proposeTool),
            new ValidateManifestTool($this->validator),
            $proposeTool,
            $planTool,
            new ScaffoldAppTool($app, $this->manifestService, $proposeTool, app(AppScaffolder::class)),
            new AddCrudPageTool($app, $this->manifestService, $proposeTool, app(AppScaffolder::class)),
            new AddDetailPageTool($app, $this->manifestService, $proposeTool, app(AppScaffolder::class)),
            new AddDashboardPageTool($app, $this->manifestService, $proposeTool, app(AppScaffolder::class)),
            new SetBuildPlanTool($conversation),
            new TargetPlanStepsTool($conversation),
            new DeleteBlockByIdTool($app, $this->manifestService, $proposeTool),
            new SeedRecordsTool($app, $this->manifestService, $this->writer, $conversation->user, $proposeTool),
            new ListAvailableIntegrationsTool($conversation->user),
            new ListConnectorActionsTool(app(ConnectorActionResolver::class), $conversation->user),
            new VerifyWorkflowTool($app, app(WorkflowEngine::class), app(WorkflowAssertionEvaluator::class), $proposeTool, $conversation->user),
            new DiscoverIntegrationTool($this->integrationAuthoring),
            $createIntegrationTool,
            new TestIntegrationConnectionTool($this->integrationAuthoring, $conversation->user),
            new SampleEndpointTool(app(IntegrationCaller::class), $conversation->user),
            new SampleMcpToolTool(app(McpClient::class), $conversation->user),
            // The clock every model must ground time-relative reasoning on
            // (dashboards, date filters, "last N days"). Not in the Builder's
            // PlatformToolsFactory path, so bridged in explicitly.
            RuntimeToolFactory::named('current_datetime', new McpBridgeTool(CurrentDatetimeTool::class, $conversation->user)),
        ];

        // History excludes the placeholder we're about to fill. reorder()
        // clears the relation's default created_at ASC sort so orderByDesc
        // actually selects the most recent N (otherwise it appends a no-op
        // tiebreaker, the query stays ascending, and we'd take the OLDEST N
        // and then reverse them into the wrong order — scrambling context).
        $history = $conversation->messages()
            ->where('id', '!=', $placeholder->id)
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        $sdkHistory = [];
        foreach ($history as $m) {
            $content = (string) ($m->content ?? '');
            if ($content === '') {
                continue;
            }
            $sdkHistory[] = $m->role === 'user'
                ? new UserMessage($content)
                : new AssistantMessage($content);
        }

        // Drop the last user turn — the prompt() / stream() call takes it
        // as a separate argument.
        $lastUser = null;
        if (! empty($sdkHistory)) {
            $tail = end($sdkHistory);
            if ($tail instanceof UserMessage) {
                $lastUser = $tail;
                array_pop($sdkHistory);
            }
        }
        $promptText = $lastUser?->content ?? $userText;
        $promptText = $this->withPlanContext($promptText, $conversation);

        $systemPrompt = $this->systemPrompt($app);
        $sdkAgent = new BuilderAgent(
            instructions: $systemPrompt,
            messages: $sdkHistory,
            tools: $tools,
        );
        // Cache the static system + tool-definition prefix (re-sent every turn)
        // as an Anthropic prompt-cache breakpoint (~0.1x after the first turn).
        if (config('ai.prompt_caching.enabled')) {
            $sdkAgent->withCacheableSystem($systemPrompt);
        }

        $startedAt = microtime(true);
        $buffer = '';

        // Pick the model: the caller can override (visual review uses Sonnet
        // 4.5 because Haiku tended to ignore the "don't add new features"
        // hard scope limit when the screenshot looked incomplete). Default
        // stays at Haiku for the cheap-and-fast common chat path.
        $resolvedModel = $this->aiDefaults->model('builder', $modelOverride);
        $sdkAgent->forModel($resolvedModel);

        try {
            $user = $conversation->user;
            if ($user !== null) {
                $this->providers->applyRuntimeConfig($user);
                $provider = $this->providers->resolveProviderForCatalogModel($resolvedModel, $user) ?? Lab::Anthropic;
            } else {
                $provider = Lab::Anthropic;
            }

            // Attach the image as a vision input when the caller sent one
            // (either the "Pedir revisión visual" screenshot or a paperclip /
            // paste / drag attachment in the chat). StoredImage reads from
            // whatever Storage disk persisted it, so it works for S3,
            // S3-compatible buckets and Storage::fake() in tests.
            $attachments = [];
            if ($attachmentPath !== null && $attachmentDisk !== null) {
                // Re-register the CloudProvider-backed disk: this runs in a
                // queue worker that never saw the write-time config, so the
                // persisted name (and StoredImage's internal disk lookup)
                // would otherwise fail to resolve.
                $attachmentDisk = $this->tenantStorage->ensureRegistered($attachmentDisk);
                $disk = Storage::disk($attachmentDisk);
                if ($disk->exists($attachmentPath)) {
                    $attachments[] = new StoredImage($attachmentPath, $attachmentDisk);
                } else {
                    Log::warning('Builder AI: attachment missing on disk', [
                        'disk' => $attachmentDisk,
                        'path' => $attachmentPath,
                    ]);
                }
            }

            Log::info('Builder AI streaming with', [
                'conversation_id' => $conversation->id,
                'model' => $resolvedModel,
                'is_override' => $modelOverride !== null,
            ]);

            app(AiSpendGuard::class)->assertWithinBudget(
                $conversation->user, $app->organization_id, $resolvedModel,
            );

            $stream = $sdkAgent->stream(
                $promptText,
                attachments: $attachments,
                provider: $provider,
                model: $resolvedModel,
            );

            // Live feedback: announce which model is now working before the first
            // token, so the UI shows "thinking with <model>" immediately.
            $this->safeBroadcast(fn () => BuilderActivity::dispatch(
                $conversation->id,
                $placeholder->id,
                'thinking',
                $resolvedModel,
            ));

            $sawText = false;
            foreach ($stream as $event) {
                // Provider/stream errors arrive as an Error EVENT, not an
                // exception — OpenRouter (unlike Anthropic) reports SSE-level and
                // upstream-provider failures this way. Without handling it the
                // turn would end silently with an empty reply, or surface as an
                // opaque blank "request failed". Throw with the code + message so
                // the catch logs and shows the real reason.
                if ($event instanceof StreamingError) {
                    throw new RuntimeException(
                        trim(($event->type ?? '').': '.($event->message ?? '')) ?: 'Provider returned a stream error.'
                    );
                }

                // Surface each tool the model invokes so the user sees what it is
                // doing (reading the manifest, simulating a query, proposing a
                // change…) instead of an opaque pause before a patch appears.
                if ($event instanceof ToolCall) {
                    $this->safeBroadcast(fn () => BuilderActivity::dispatch(
                        $conversation->id,
                        $placeholder->id,
                        'tool',
                        $resolvedModel,
                        $event->toolCall->name,
                    ));

                    continue;
                }

                // When Claude uses tools it emits several separate text blocks
                // across the turn (one before each tool call, one after). Each
                // is bounded by TextStart/TextEnd, and the SDK puts NOTHING
                // between them — so concatenating the deltas glued the end of
                // one block onto the start of the next ("...temáticas.Dejaré").
                // Insert a paragraph break when a new block opens after we've
                // already streamed some text, and broadcast it so the live
                // view matches the persisted buffer.
                if ($event instanceof TextStart) {
                    $separator = self::blockSeparator($buffer, $sawText);
                    if ($separator !== '') {
                        $buffer .= $separator;
                        $this->safeBroadcast(fn () => BuilderStreamChunk::dispatch(
                            $conversation->id,
                            $placeholder->id,
                            $separator,
                        ));
                    }

                    continue;
                }

                if ($event instanceof TextDelta && $event->delta !== '') {
                    if (! $sawText) {
                        // First token of the reply — flip the live status from
                        // "thinking / using a tool" to "writing".
                        $this->safeBroadcast(fn () => BuilderActivity::dispatch(
                            $conversation->id,
                            $placeholder->id,
                            'writing',
                            $resolvedModel,
                        ));
                    }
                    $sawText = true;
                    $buffer .= $event->delta;
                    $this->safeBroadcast(fn () => BuilderStreamChunk::dispatch(
                        $conversation->id,
                        $placeholder->id,
                        $event->delta,
                    ));
                }
            }

            Log::info('Builder AI stream finished', [
                'conversation_id' => $conversation->id,
                'message_id' => $placeholder->id,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'response_length' => strlen($buffer),
                'has_proposal' => $proposeTool->lastProposal() !== null,
            ]);

            app(AiUsageRecorder::class)->record(
                'builder', $resolvedModel, $conversation->user, $app->organization_id, $stream->usage ?? null,
            );

            // apply=false (MCP "leave pending for review"): keep the accumulated
            // proposal on the placeholder as `pending` without creating a version.
            // No version lands; the caller applies it in-app or with a later turn.
            $proposal = $proposeTool->lastProposal();
            if (! $applyProposal && $proposal !== null) {
                $released = $this->applyPlanProgress($conversation, [
                    'status' => 'pending',
                    'applied_version_id' => null,
                    'error' => null,
                    'change_summary' => $proposal['summary'] ?? null,
                ]);
                $placeholder->update([
                    'content' => $buffer,
                    'proposed_patch' => $proposal['patch'],
                    'change_summary' => $proposal['summary'] ?? null,
                    'plan' => $planTool->plan(),
                    'integration_proposal' => $createIntegrationTool->proposal(),
                    'status' => 'pending',
                    'applied_version_id' => null,
                    'plan_step_ids' => $released !== [] ? $released : null,
                ]);
                $this->safeBroadcast(fn () => BuilderStreamComplete::dispatch($placeholder->refresh()));

                return $placeholder;
            }

            $commit = $this->commitTurn($app, $proposal, $conversation->user, $buffer);

            if ($commit['error'] !== null) {
                // The model already streamed a summary as if the change landed,
                // but persisting it failed. Feed the real outcome back to the
                // model and let it write an honest correction in the user's
                // language; fall back to the deterministic notice baked into
                // commitTurn() if that follow-up turn itself fails. We finish
                // with StreamComplete (not StreamError) so the streamed
                // correction stands instead of being clobbered by the generic
                // error text.
                $content = $this->reconcileSaveFailure(
                    $conversation,
                    $placeholder,
                    $sdkHistory,
                    $promptText,
                    $buffer,
                    $commit['error'],
                    $resolvedModel,
                    $provider,
                ) ?? $commit['content'];

                $failedSteps = $this->applyPlanProgress($conversation, $commit);

                $placeholder->update([
                    'content' => $content,
                    'proposed_patch' => $commit['proposed_patch'],
                    'change_summary' => $commit['change_summary'],
                    'status' => 'none',
                    'applied_version_id' => null,
                    'plan_step_ids' => $failedSteps !== [] ? $failedSteps : null,
                ]);

                $this->safeBroadcast(fn () => BuilderStreamComplete::dispatch($placeholder->refresh()));

                return $placeholder;
            }

            $closedSteps = $this->applyPlanProgress($conversation, $commit);

            $placeholder->update([
                'content' => $commit['content'],
                'proposed_patch' => $commit['proposed_patch'],
                'change_summary' => $commit['change_summary'],
                'plan' => $planTool->plan(),
                'integration_proposal' => $createIntegrationTool->proposal(),
                'status' => $commit['status'],
                'applied_version_id' => $commit['applied_version_id'],
                'plan_step_ids' => $closedSteps !== [] ? $closedSteps : null,
            ]);

            $this->safeBroadcast(fn () => BuilderStreamComplete::dispatch($placeholder->refresh()));

            return $placeholder;
        } catch (\Throwable $e) {
            // For HTTP failures (e.g. the provider rejecting the request), the
            // generic message is just "HTTP request returned status code 400" —
            // the actual reason is in the response body. Surface it so model/
            // request problems are diagnosable instead of opaque.
            $providerError = null;
            if ($e instanceof RequestException && $e->response !== null) {
                $body = json_decode($e->response->body(), true);
                $providerError = $body['error']['message'] ?? mb_substr($e->response->body(), 0, 500);
            }

            Log::error('Builder AI stream failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $placeholder->id,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'error_class' => $e::class,
                'error' => $e->getMessage(),
                'provider_error' => $providerError,
                'model' => $resolvedModel,
            ]);

            // Cap the error string so we don't try to persist a 5KB SQL trace
            // (which can recursively trigger the very error we're handling
            // when the original error was a column-length overflow). Fall back to
            // the exception class when there's no message, so the bubble is never
            // a blank "request failed:" with no clue.
            $errMsg = mb_substr($providerError ?? $e->getMessage(), 0, 1500);
            if (trim($errMsg) === '') {
                $errMsg = $e::class;
            }
            $placeholder->update([
                'content' => 'Sorry — the AI request failed: '.$errMsg,
                'status' => 'none',
            ]);

            $this->safeBroadcast(fn () => BuilderStreamError::dispatch(
                $conversation->id,
                $placeholder->id,
                $errMsg,
            ));

            return $placeholder;
        }
    }

    /**
     * Decide the separator to insert when a new streamed text block opens.
     *
     * Claude emits a fresh TextStart/TextEnd-bounded block before and after
     * every tool call. The SDK puts nothing between consecutive blocks, so
     * naive concatenation glues the end of one onto the start of the next
     * ("...temáticas.Dejaré..."). We insert a paragraph break before any block
     * that follows already-streamed text, unless the buffer already ends in a
     * newline (the model closed the previous block with its own break).
     */
    public static function blockSeparator(string $buffer, bool $sawText): string
    {
        if ($sawText && $buffer !== '' && ! str_ends_with($buffer, "\n")) {
            return "\n\n";
        }

        return '';
    }

    /**
     * Broadcasts go to Reverb over HTTP. If Reverb is misconfigured or down,
     * we must NOT crash the job — the message is already persisted in DB,
     * so a page refresh will pick it up. We log once per turn and continue.
     */
    private function safeBroadcast(\Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            // Log only once per second to avoid filling logs with one entry
            // per delta when the broadcaster is dead.
            static $lastWarn = 0;
            if (microtime(true) - $lastWarn > 1) {
                Log::warning('Builder AI broadcast failed (continuing)', [
                    'error' => $e->getMessage(),
                ]);
                $lastWarn = microtime(true);
            }
        }
    }

    /**
     * Apply a previously-proposed patch to the manifest and mark the message
     * as applied. Returns the new AppVersion.
     *
     * Idempotent against double-clicks: if the message is already applied,
     * returns the existing AppVersion instead of erroring or applying twice.
     */
    public function approveProposal(BuilderMessage $message, User $user): AppVersion
    {
        if ($message->status === 'applied' && $message->applied_version_id !== null) {
            $existing = AppVersion::query()->find($message->applied_version_id);
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($message->status !== 'pending' || $message->proposed_patch === null) {
            throw new \DomainException('Message has no pending proposal.');
        }

        $app = $message->conversation->app;

        return DB::transaction(function () use ($message, $app, $user): AppVersion {
            $version = $this->manifestService->applyPatch(
                $app,
                $message->proposed_patch,
                $user,
                $message->change_summary ?? 'Builder AI change',
            );

            $message->update([
                'status' => 'applied',
                'applied_version_id' => $version->id,
            ]);

            return $version;
        });
    }

    /**
     * Close out the conversation's build plan after a turn, deterministically
     * from the turn's outcome (NOT from the model's prose): the steps the model
     * targeted (in_progress) become done when a version actually applied, failed
     * when the apply errored, or pending again when the turn proposed nothing —
     * which is exactly how a "phantom turn" leaves the plan unchanged. Returns the
     * targeted step ids for the message's plan_step_ids. No-op without a plan or
     * targeted steps.
     *
     * @param  array<string, mixed>  $commit  the commitTurn() result
     * @return list<string>
     */
    private function applyPlanProgress(BuilderConversation $conversation, array $commit): array
    {
        $conversation->refresh();
        $plan = $conversation->build_plan;
        if (! is_array($plan)) {
            return [];
        }

        $targeted = BuildPlan::inProgressIds($plan);
        if ($targeted === []) {
            return [];
        }

        if (($commit['status'] ?? null) === 'applied' && ($commit['applied_version_id'] ?? null) !== null) {
            $versionNumber = AppVersion::query()->whereKey($commit['applied_version_id'])->value('version_number');
            $plan = BuildPlan::closeApplied(
                $plan,
                $commit['applied_version_id'],
                $versionNumber !== null ? (int) $versionNumber : null,
                $commit['change_summary'] ?? null,
            );
        } elseif (($commit['error'] ?? null) !== null) {
            $plan = BuildPlan::failInProgress($plan, mb_substr((string) $commit['error'], 0, 500));
        } else {
            $plan = BuildPlan::resetInProgress($plan);
        }

        $conversation->update(['build_plan' => $plan]);

        return $targeted;
    }

    /**
     * Decide whether autonomous mode should run another turn, given the just-
     * finished turn's outcome and the live plan. Pure (takes primitives) so the
     * loop's stop conditions are unit-testable. Continue only when budget
     * remains, the plan is still active, AND the turn actually advanced the plan
     * (a real version closed ≥1 step) — so a phantom/no-op turn HALTS the loop
     * instead of spinning. One allowance: a turn that just AUTHORED the plan
     * (set_build_plan then stop, per rule 1d-plan) continues even though it
     * closed nothing — that's the plan's kick-off.
     *
     * @param  array<string, mixed>|null  $plan
     * @param  list<string>|null  $closedStepIds
     * @return array{continue: bool, reason: string}
     */
    public function autonomousDecision(?array $plan, string $turnStatus, ?array $closedStepIds, int $remaining, bool $planJustCreated = false): array
    {
        if ($remaining <= 0) {
            return ['continue' => false, 'reason' => 'cap'];
        }
        if (! is_array($plan) || ($plan['status'] ?? null) !== 'active') {
            return ['continue' => false, 'reason' => 'plan_complete'];
        }
        if ($planJustCreated) {
            return ['continue' => true, 'reason' => 'plan_created'];
        }
        if ($turnStatus !== 'applied' || empty($closedStepIds)) {
            return ['continue' => false, 'reason' => 'no_progress'];
        }

        return ['continue' => true, 'reason' => 'continue'];
    }

    /**
     * Whether the conversation's build plan was authored/edited during the
     * given turn — set_build_plan stamps `updated_at` on the plan, and the
     * placeholder message's created_at marks the turn start.
     *
     * @param  array<string, mixed>|null  $plan
     */
    private static function planUpdatedDuringTurn(?array $plan, BuilderMessage $finished): bool
    {
        $stamp = $plan['updated_at'] ?? null;
        if (! is_string($stamp) || $stamp === '' || $finished->created_at === null) {
            return false;
        }

        try {
            return Carbon::parse($stamp)->gte($finished->created_at);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Plan-driven autonomy: when a NORMAL (user-initiated) turn leaves an
     * ACTIVE build plan behind — either it advanced the plan or it just
     * authored it — the platform keeps executing the pending steps itself,
     * without the UI's autonomous toggle and without the user typing
     * "continúa". Called by RunBuilderAiJob for turns that carried no
     * autonomous budget and were not themselves auto-queued (so an exhausted
     * chain can never re-seed itself into an infinite loop). The chained turns
     * then self-limit through autonomousDecision (no_progress / cap / done).
     */
    public function continueFromPlan(BuilderMessage $finished, ?string $modelOverride = null): void
    {
        $conversation = $finished->conversation;
        $conversation->refresh();
        $plan = $conversation->build_plan;

        if (! is_array($plan) || ($plan['status'] ?? null) !== 'active') {
            return;
        }

        // Kick only from a turn that ENGAGED the plan; a casual Q&A turn while
        // a half-done plan sits idle must not surprise-launch an auto build.
        $engaged = ((string) $finished->status === 'applied' && ! empty($finished->plan_step_ids))
            || self::planUpdatedDuringTurn($plan, $finished);
        if (! $engaged) {
            return;
        }

        $this->continueAutonomously($finished, self::AUTONOMOUS_MAX_TURNS, $modelOverride);
    }

    /**
     * Resume a build whose turn was cut off by the wall-clock timeout, once its
     * checkpoint banked REAL progress (the caller only invokes this after a new
     * version landed). With slow models a timeout is the expected rhythm, not an
     * exception, so the platform re-queues the next turn itself instead of
     * asking the user to type "continúa" — whether or not the model set an
     * explicit build_plan (a connected-dashboard build is naturally two banked
     * turns: object, then compiled page). Only an EXPLICITLY finished plan stops
     * it. Bounded by $resumeRemaining consecutive timeout-resumes; a resume turn
     * that banks nothing new never reaches here, so it self-terminates. Returns
     * whether a resume was queued (the caller words the timeout note).
     */
    public function resumeAfterTimeout(BuilderMessage $finished, ?string $modelOverride, int $autonomousRemaining, int $resumeRemaining): bool
    {
        if ($resumeRemaining <= 0) {
            return false;
        }

        $conversation = $finished->conversation;
        $conversation->refresh();
        $plan = $conversation->build_plan;
        // A plan the model marked complete/abandoned means the build is done —
        // don't resume over it. No plan, or an active one, resumes.
        if (is_array($plan) && in_array($plan['status'] ?? 'active', ['done', 'abandoned'], true)) {
            return false;
        }

        $this->queueAutoTurn(
            $conversation,
            '(auto-reanudación) El turno anterior se quedó sin tiempo pero el progreso está guardado. Continúa exactamente donde quedó con el siguiente paso pendiente (si hay un plan, márcalo con target_plan_steps).',
            $modelOverride,
            max($autonomousRemaining, self::AUTONOMOUS_MAX_TURNS),
            $resumeRemaining - 1,
        );

        return true;
    }

    /**
     * After an autonomous turn, either queue the next one (one turn per job, so
     * each respects the per-turn wall-clock) or post a short note explaining why
     * the loop stopped. Called by RunBuilderAiJob; a no-op when $remaining is 0.
     */
    public function continueAutonomously(BuilderMessage $finished, int $remaining, ?string $modelOverride = null): void
    {
        if ($remaining <= 0) {
            return;
        }

        $conversation = $finished->conversation;
        $conversation->refresh();

        $decision = $this->autonomousDecision(
            $conversation->build_plan,
            (string) $finished->status,
            $finished->plan_step_ids,
            $remaining,
            self::planUpdatedDuringTurn($conversation->build_plan, $finished),
        );

        if (! $decision['continue']) {
            $this->noteAutonomousStop($conversation, $decision['reason']);

            return;
        }

        $this->queueAutoTurn(
            $conversation,
            '(modo autónomo) Continúa con el siguiente paso pendiente del plan: márcalo con target_plan_steps y aplícalo con propose_change.',
            $modelOverride,
            $remaining - 1,
        );
    }

    /**
     * Queue a server-driven follow-up turn: persist the synthetic user turn +
     * streaming placeholder, push them to the client (no HTTP response carries
     * them), and dispatch the job flagged autoQueued so an exhausted chain can
     * never re-seed itself through continueFromPlan.
     */
    private function queueAutoTurn(BuilderConversation $conversation, string $prompt, ?string $modelOverride, int $remaining, int $resumeRemaining = 2): void
    {
        $userTurn = BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $prompt,
            'status' => 'none',
        ]);

        $placeholder = BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'status' => 'streaming',
        ]);

        $this->safeBroadcast(fn () => BuilderTurnQueued::dispatch($conversation->id, [
            $this->autonomousTurnDto($userTurn),
            $this->autonomousTurnDto($placeholder),
        ]));

        RunBuilderAiJob::dispatch($placeholder->id, $prompt, null, null, $modelOverride, $remaining, true, true, $resumeRemaining);
    }

    /**
     * Post a one-line assistant note when the autonomous loop stops, so the user
     * sees why. Silent for an ordinary completed plan with nothing left to say.
     */
    private function noteAutonomousStop(BuilderConversation $conversation, string $reason): void
    {
        $text = match ($reason) {
            'plan_complete' => '✅ Plan completado.',
            'cap' => 'Pausé el modo autónomo tras varios pasos automáticos. Dime «continúa» para seguir.',
            'no_progress' => 'Pausé el modo autónomo: el último turno no avanzó el plan. ¿Cómo quieres que siga?',
            default => null,
        };
        if ($text === null) {
            return;
        }

        $note = BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $text,
            'status' => 'none',
        ]);

        $this->safeBroadcast(fn () => BuilderTurnQueued::dispatch($conversation->id, [
            $this->autonomousTurnDto($note),
        ]));
    }

    /**
     * Serialize a freshly-created message for BuilderTurnQueued — same shape the
     * frontend's Message uses, so it can append it directly.
     *
     * @return array<string, mixed>
     */
    private function autonomousTurnDto(BuilderMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'proposed_patch' => $m->proposed_patch,
            'change_summary' => $m->change_summary,
            'plan' => $m->plan,
            'integration_proposal' => $m->integration_proposal,
            'status' => $m->status,
            'applied_version_id' => $m->applied_version_id,
            'plan_step_ids' => $m->plan_step_ids,
            'attachment_url' => null,
            'attachment_mime' => $m->attachment_mime,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /**
     * Prepend the active build plan to the turn's prompt so the model always sees
     * what's pending without spending a tool call. Non-cached (it changes per
     * turn). No-op without an active, unfinished plan.
     */
    private function withPlanContext(string $promptText, BuilderConversation $conversation): string
    {
        $plan = $conversation->build_plan;
        if (! is_array($plan) || ($plan['steps'] ?? []) === [] || ($plan['status'] ?? null) !== 'active') {
            return $promptText;
        }

        $lines = BuildPlan::toContextLines($plan);

        return "[Plan de construcción en curso. Trabaja el/los siguiente(s) paso(s) pendiente(s); llama target_plan_steps con sus ids ANTES de propose_change. El avance se cierra solo cuando tu propose_change se aplica — no marques pasos como hechos tú.]\n{$lines}\n\n{$promptText}";
    }

    /**
     * Commit a finished turn: apply the accumulated proposal as a new manifest
     * version and return the message fields to persist. A turn with no proposal
     * is a no-op (status 'none').
     *
     * Crucially, when the apply FAILS — the model validated the draft inside
     * the tool loop, but persisting it threw (e.g. a permission/role error
     * reaching the platform schema, an invalid result, or a race against a
     * parallel edit) — this does NOT swallow it. The model has usually already
     * streamed a summary that reads like success; we bake the real failure
     * reason into the message content (so it survives a page reload, not just a
     * log line) and return it as `error` so the caller broadcasts it. Public so
     * it reads as the commit twin of applyCheckpoint().
     *
     * @param  array{patch: list<array<string, mixed>>, summary?: string|null}|null  $proposal
     * @return array{content: string, proposed_patch: ?list<array<string, mixed>>, change_summary: ?string, status: string, applied_version_id: ?string, error: ?string}
     */
    public function commitTurn(App $app, ?array $proposal, ?User $user, string $buffer): array
    {
        if ($proposal === null) {
            return [
                'content' => $buffer,
                'proposed_patch' => null,
                'change_summary' => null,
                'status' => 'none',
                'applied_version_id' => null,
                'error' => null,
            ];
        }

        try {
            $version = $this->manifestService->applyPatch(
                $app,
                $proposal['patch'],
                $user,
                $proposal['summary'] ?? 'Builder AI change',
            );

            return [
                'content' => $buffer,
                'proposed_patch' => $proposal['patch'],
                'change_summary' => $proposal['summary'] ?? null,
                'status' => 'applied',
                'applied_version_id' => $version->id,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $reason = mb_substr($e->getMessage(), 0, 1500);

            Log::error('Builder AI auto-apply failed — changes NOT saved', [
                'app_id' => $app->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'content' => $buffer.self::saveFailureNotice($reason),
                'proposed_patch' => $proposal['patch'],
                'change_summary' => $proposal['summary'] ?? null,
                'status' => 'none',
                'applied_version_id' => null,
                'error' => $reason,
            ];
        }
    }

    /**
     * Notice appended to an assistant message when the end-of-turn apply failed,
     * so the user sees nothing was saved even though the streamed summary may
     * have claimed otherwise.
     */
    public static function saveFailureNotice(string $reason): string
    {
        return "\n\n---\n\n⚠️ **Changes were not saved.** The server rejected the proposal while applying it: "
            .$reason
            ."\n\nThe proposal is kept on this message — adjust and resend, or retry.";
    }

    /**
     * After an end-of-turn save failed, run one short, tool-less follow-up turn
     * that is told the real outcome, so the closing message the user sees is the
     * model's own honest correction rather than the optimistic summary it
     * streamed before the apply ran. Streams the correction as chunks (appended
     * after the original text) and returns the full content to persist, or null
     * if the follow-up produced nothing usable / threw — in which case the
     * caller falls back to the deterministic notice from commitTurn().
     *
     * @param  list<UserMessage|AssistantMessage>  $sdkHistory
     */
    private function reconcileSaveFailure(
        BuilderConversation $conversation,
        BuilderMessage $placeholder,
        array $sdkHistory,
        string $promptText,
        string $buffer,
        string $error,
        string $model,
        Lab $provider,
    ): ?string {
        try {
            $history = $sdkHistory;
            $history[] = new UserMessage($promptText);
            $history[] = new AssistantMessage($buffer !== '' ? $buffer : '(no closing summary)');

            $agent = new BuilderAgent(
                instructions: self::saveFailureReconcileInstructions(),
                messages: $history,
                tools: [],
            );

            $stream = $agent->stream(
                self::saveFailureReconcilePrompt($error),
                provider: $provider,
                model: $model,
            );

            $separator = "\n\n---\n\n";
            $this->safeBroadcast(fn () => BuilderStreamChunk::dispatch(
                $conversation->id, $placeholder->id, $separator,
            ));

            $correction = '';
            foreach ($stream as $event) {
                if ($event instanceof TextDelta && $event->delta !== '') {
                    $correction .= $event->delta;
                    $this->safeBroadcast(fn () => BuilderStreamChunk::dispatch(
                        $conversation->id, $placeholder->id, $event->delta,
                    ));
                }
            }

            app(AiUsageRecorder::class)->record(
                'builder', $model, $conversation->user, $conversation->organization_id, $stream->usage ?? null,
            );

            if (trim($correction) === '') {
                return null;
            }

            return $buffer.$separator.$correction;
        } catch (\Throwable $e) {
            Log::warning('Builder AI save-failure reconcile turn failed; using deterministic notice', [
                'message_id' => $placeholder->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * System prompt for the save-failure reconcile turn: a tiny, single-purpose
     * agent that owns up to a change that did not persist.
     */
    public static function saveFailureReconcileInstructions(): string
    {
        return <<<'TXT'
        You are the Sapiensly app builder. The change you just described to the user was NOT saved — applying it
        failed on the server, so the app's manifest is UNCHANGED. Write a brief, honest closing message to the user.

        Rules:
        - Reply in the SAME language the user has been using.
        - Do NOT claim the change was made — it was not.
        - State plainly that it could not be saved, give the reason in plain words, and say they can retry or rephrase.
        - Be concise (2-4 sentences). No tool calls, no JSON, no patches — just the message.
        TXT;
    }

    /**
     * The reconcile turn's user message: the raw apply error the model must own.
     */
    public static function saveFailureReconcilePrompt(string $error): string
    {
        return "SYSTEM NOTICE (not from the user): applying your proposed changes failed with this error: «{$error}». "
            .'The manifest was not changed. Now write your honest correction to the user.';
    }

    /**
     * Recover a turn that died (timeout/crash) mid-loop: apply the accumulated
     * valid patch checkpointed onto the placeholder during the turn, so the
     * work isn't lost and the next turn resumes from the real manifest instead
     * of restarting from an empty app. Called from RunBuilderAiJob::failed().
     * Returns the new version, or null if there was nothing valid to bank.
     */
    public function applyCheckpoint(BuilderMessage $message): ?AppVersion
    {
        if ($message->status === 'applied' || empty($message->proposed_patch)) {
            return null;
        }

        $app = $message->conversation->app;
        $user = $message->conversation->user;

        $version = $this->manifestService->applyPatch(
            $app,
            $message->proposed_patch,
            $user,
            $message->change_summary ?? 'Builder AI change (recovered after timeout)',
        );

        $message->update([
            'status' => 'applied',
            'applied_version_id' => $version->id,
        ]);

        Log::info('Builder AI checkpoint recovered after interrupted turn', [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'version_id' => $version->id,
        ]);

        return $version;
    }

    /**
     * Idempotent: rejecting an already-rejected message is a no-op.
     */
    public function rejectProposal(BuilderMessage $message): void
    {
        if ($message->status === 'rejected') {
            return;
        }

        if ($message->status !== 'pending') {
            throw new \DomainException('Message has no pending proposal.');
        }

        $message->update(['status' => 'rejected']);
    }

    /**
     * Roll back the version this message applied: copies the manifest of the
     * version that was current BEFORE this patch landed and bumps it forward
     * as a new version (append-only history). Marks the message as reverted.
     *
     * Idempotent: re-calling on an already-reverted message is a no-op.
     */
    public function revertMessage(BuilderMessage $message, User $user): AppVersion
    {
        if ($message->status === 'reverted') {
            // Return whatever is current — the previous revert already moved us back.
            return $message->conversation->app->currentVersion;
        }

        if ($message->status !== 'applied' || $message->applied_version_id === null) {
            throw new \DomainException('Message has no applied version to revert.');
        }

        $appliedVersion = AppVersion::query()->findOrFail($message->applied_version_id);
        $previousVersion = AppVersion::query()
            ->where('app_id', $appliedVersion->app_id)
            ->where('version_number', '<', $appliedVersion->version_number)
            ->orderByDesc('version_number')
            ->first();

        if ($previousVersion === null) {
            throw new \DomainException('No earlier version exists to revert to.');
        }

        $app = $message->conversation->app;
        $newVersion = $this->manifestService->rollbackTo($app, $previousVersion, $user);

        $message->update(['status' => 'reverted']);

        // Reopen any build-plan steps this version had closed — the plan must not
        // claim "done" over work no longer live in the manifest.
        $conversation = $message->conversation;
        if (is_array($conversation->build_plan)) {
            $conversation->update([
                'build_plan' => BuildPlan::reopenForVersion($conversation->build_plan, $appliedVersion->id),
            ]);
        }

        return $newVersion;
    }

    /**
     * Build the chat history the SDK needs: alternating UserMessage and
     * AssistantMessage instances ending in a UserMessage (the new turn).
     *
     * @return array<UserMessage|AssistantMessage>
     */
    private function buildHistory(BuilderConversation $conversation): array
    {
        $messages = $conversation
            ->messages()
            ->orderByDesc('created_at')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        $sdk = [];
        foreach ($messages as $m) {
            $content = (string) ($m->content ?? '');
            if ($content === '') {
                continue;
            }
            $sdk[] = $m->role === 'user'
                ? new UserMessage($content)
                : new AssistantMessage($content);
        }

        return $sdk;
    }

    private function systemPrompt(App $app): string
    {
        $brandbook = $this->brandbookSection($app);
        $now = CurrentDateTime::promptLine();

        return <<<PROMPT
{$now}

You are the Builder AI inside Sapiensly. Your job is to help the tenant edit a low-code App by modifying its JSON manifest.

App details:
  id: {$app->id}
  slug: {$app->slug}
  name: {$app->name}

{$brandbook}

Language:
0. ALWAYS reply in the same language as the most recent user message. If the user writes in Spanish, your assistant turn (including confirmations, error messages, clarifications and the `change_summary` you pass to propose_change) is in Spanish. If they switch to English mid-conversation, switch with them. Don't mix languages within a single reply. Default to the user's language even if your internal reasoning was in English.

Rules of engagement:
1. ALWAYS call `read_manifest` first to see the current structure before proposing edits. By default it returns `{state, op_count, note, summary}` — a COMPACT structural map (objects→fields with id/slug/type, pages→blocks with id/type, workflows, settings keys, agent on/off), NOT full property values. That summary is enough to locate what you need. To edit a specific object/page/workflow, call `read_manifest` again with `expand: "<id>"` to get that ONE element's full definition (returned as `element`); only expand what you're about to change, never the whole manifest element-by-element. After you've made one or more successful `propose_change` calls, `state` flips to "draft" and the summary/element reflect the in-progress draft (with your ops already applied) — DO NOT re-propose what's already there. If `propose_change` returned ok:true, the change is in the draft, even if your previous read showed it absent. Re-reading is only useful between calls that depend on the new structure (e.g. you added a field and now need the field id to reference from a form).
1a. BUILD ON WHAT EXISTS — NEVER restart from scratch. If `read_manifest` shows objects/pages already there (e.g. on a "continúa" turn, or after an earlier turn), ADD to them with small incremental patches. Do NOT delete-and-recreate objects/pages you already built, and do NOT re-create an object that already exists — your earlier work is saved (progress is checkpointed even if a previous turn was cut off). Empty or partial is fine; pick up exactly where the manifest left off.
1b. CONSULT BEFORE YOU BUILD, not after. Before composing ANY block/field/action/workflow you are not 100% sure of, call the relevant catalog FIRST (list_available_components / list_available_field_types / list_available_actions / list_available_triggers / list_available_steps) and, for an area you're unsure of, `framework_reference(topic)`. Guessing a shape and learning it from a validation error wastes a whole round-trip — and there is a hard time budget per turn. Read once, then build it right.
1c. KEEP PATCHES SMALL. Add a few blocks/fields per `propose_change` call (they accumulate across calls in the turn). Do NOT try to submit an entire page of many blocks + modals in one giant op — very large tool arguments can be truncated in transit and arrive malformed (you'll see "ops must be a non-empty array" or apply errors even though your patch looked complete). Several small valid calls beat one huge fragile one.
1c-checkpoint. BANK PROGRESS EARLY — there is a HARD per-turn time budget, and a turn that samples/plans/reads for minutes and then runs out of time leaves NOTHING saved (only an applied `propose_change` is checkpointed). So reach your FIRST `propose_change` as fast as possible: do the minimum exploration needed for it, commit, THEN continue. Concretely for a dashboard from a connected source: ONE quick `sample_mcp_tool`/`sample_endpoint` call to see the field shape → IMMEDIATELY `propose_change` the object (internal, or the live connected object per 1c-intent) → then ONE `add_dashboard_page` call compiles the whole page (also banked). Do NOT sample repeatedly or read every catalog before your first commit. If the build clearly won't fit one turn, `set_build_plan` the parts (① object ② compiled dashboard page ③ refinement) and do the next part per turn, ending with STOP — each turn banks its piece and THE PLATFORM CONTINUES THE PLAN AUTOMATICALLY, turn by turn, until it's done (even across timeouts). Never ask the user to say "continúa". Slower models MUST lean on this: object first (banked), add_dashboard_page next (banked).
1c-intent. CLASSIFY THE INTENT BEFORE YOU BUILD — dashboard vs app. A request to ANALYZE / REPORT / VISUALIZE / see a TABLERO or DASHBOARD ("crea un dashboard para analizar X", "analiza los X", "reporte de X", "visualiza X", "tablero de X", "un dashboard con esa información") is a DASHBOARD build, NOT an app build. For a dashboard: do NOT call `scaffold_app` — it builds CRUD pages, entry forms, master-detail screens and a POS, none of which a dashboard needs, and it buries the ask. Instead: (1) DATA — if the app already has the object(s), use them; if the data must come from a connected source, sample its shape (`sample_mcp_tool` for MCP, `sample_endpoint` for REST) and PREFER a LIVE CONNECTED object (an object whose `source` is {type:"connected", integration_id, operations:{list:{…}}, id_path, field_map} — for MCP the list op is {mcp_tool, arguments?, collection_path?}; see the `connected_objects` topic) so the dashboard reads the source live at render time — no `seed_records`, no per-row create_record loop (slow, times out). Seed only for a deliberate frozen snapshot, or create a minimal object only if there's genuinely no source. Never seed invented demo data when a real source is connected. (2) DASHBOARD — go straight to the dashboard flow (rule 1d-dash): `prepare_dashboard` (one call: blueprint + profile + brand) → ONE `add_dashboard_page` call (the server compiles the whole professional page). Reserve `scaffold_app` for a "build me an APP to manage/track/run X" request where data ENTRY and record management (forms, CRUD, kanban, a POS) are the point — not analysis.
1d. COLD START — use `scaffold_app` (APP builds only, per 1c-intent — never for a dashboard request). For a "create an app for X" / "build me an app that …" request on an EMPTY app, call `scaffold_app` FIRST with ALL the `objects` (name + snake_case slug + simple fields) AND the `links` (the belongs-to relations, e.g. {from:"renglones", to:"comandas", name:"comanda"}). On an empty app it builds the whole thing in ONE validated step: objects, the relations, derived fields (child counts + money totals, and for an order→line→priced-product shape a unit-price lookup + line subtotal + order total), a page per object, master-detail pages, a dashboard, and a POS screen when the data fits. Do NOT hand-build objects/relations/derived fields op-by-op — that is slow and fragile (it thrashes on size limits). After scaffolding, only REFINE with `propose_change` (tweak a form, add a workflow, adjust a page) using the ids it returns. Model an order's line items, and a line that references a priced product, as `links` so the lookup/subtotal/total/POS are generated for you.
1d-pages. ADDING A PAGE to an app that ALREADY has objects — prefer the compact page builders over hand-writing blocks. To give an existing object a full list screen (heading + "new" form/modal + table, plus a kanban when it has a status field), call `add_crud_page` with just its `object_slug`. To give a parent object a master-detail screen (its record + an inline "add" form and a related list for each child object, wired with an "open" row action from its list), call `add_detail_page` with the parent's `object_slug`. Both assemble the whole page server-side from the object's real fields and return the new page's slug/path — use them instead of composing the page's blocks op-by-op (which thrashes on tool-argument size). Fall back to hand-built `propose_change` blocks only for a page these don't cover (e.g. a custom dashboard or a one-off layout).
1d-dash. DASHBOARDS ARE BUILT WITH `add_dashboard_page` — one compiled call, not hand-written blocks. Once the object exists: (1) call `prepare_dashboard` ONCE ({object_id, sector}) — it returns the blueprint + the data profile + the brand in one response (instead of three separate calls) so your KPIs and chart types fit the real data; (2) call `add_dashboard_page` with the CONTENT — kpis (with icons, compare/delta_good where the data allows), varied charts (never one chart_type 3×; percentiles go in KPIs, not charts), and insight cards whose body states real conclusions (ideally with a live `compute`). The SERVER lays it out (KPI band first, balanced rows with col_span weights, the date-range filter wired into every block, the compact brand hero) and runs the professional-dashboard lints before applying — if it returns errors, adjust the CONTENT and call it again. Hand-build with `plan_dashboard` + `propose_change` ONLY for an exotic layout the compiler can't express (e.g. a map/gantt/kanban-centric board) — then plan_dashboard is mandatory before the first block op. Adding/tweaking ONE block on an existing dashboard needs neither.
1d-fit. FIT THE DASHBOARD TO WHAT THE SOURCE CAN ACTUALLY ANSWER — AND SAY SO. After discovering the source (`sample_mcp_tool` / `prepare_dashboard`), compare EACH requested KPI/chart/dimension against the fields the tools really return, BEFORE building. Then: (a) NEVER fabricate a metric the source doesn't have — no invented fields, no seeded fake data, no empty placeholder blocks. (b) If a requested piece is missing but a close proxy exists, SUBSTITUTE it and label it honestly (e.g. no CSAT field → "SLA incumplidos" as the service-quality KPI) — and build the rest as asked. (c) In your reply, state plainly which requested pieces this connection could NOT answer, why (which tool/field is missing), and what you used instead — the user must never discover a gap by staring at a broken or empty card. (d) If the CORE of the request is unanswerable (the connection's tools expose no data about the asked topic at all), do NOT build a filler board: reply that this source can't answer that dashboard, say what its tools DO expose, and propose 1–2 concrete alternative dashboards it CAN answer — each named with the actual KPIs/charts you would build from the real fields — then ask which to build. This is the one dashboard case where stopping to ask beats building.
1d-plan. PLAN A MULTI-PART BUILD across turns. When a request has several distinct pieces that won't fit one turn (e.g. "add objects, then pages, then a couple of workflows"), call `set_build_plan` FIRST with the ordered steps (just {title, detail?} — the server mints ids and tracks status). Then each turn: call `target_plan_steps` with the id(s) you're about to do, make the change with `propose_change` (or scaffold/add_* tools), and STOP. You do NOT mark steps done — the platform closes a step automatically only when your `propose_change` actually applies (a turn that proposes nothing advances nothing). THE PLATFORM RUNS THE PLAN AUTOMATICALLY: after a turn that authors or advances an active plan, it queues the next turn itself until the plan completes (bounded, and it survives timeouts by resuming from the banked checkpoint) — so NEVER end a turn asking the user to say "continúa"; just STOP and the next step runs. When a plan is active it is shown to you at the top of each turn; work the next pending step(s). Use a plan only for genuinely multi-step builds — a single small edit needs no plan.
1e. PLAN BEFORE YOU BUILD a workflow. For a request to create a new workflow, automation or multi-step flow — ESPECIALLY one that touches an external system (a connector.call) — call `propose_plan` FIRST with the trigger, the ordered steps, every external system each step touches (read vs write), and your assumptions as defaults the user can change. Then STOP for that turn: present the plan in plain language and do NOT call `propose_change`. The user approves, edits or discards the plan from the card; only on the next turn (after approval) do you build it with `propose_change`. Before composing a connector step, call `list_available_integrations` then `list_connector_actions` so the plan names real systems and effects. Skip `propose_plan` only for a small, unambiguous tweak to an existing flow.
1f. PROVISION WHAT'S MISSING — by proposal, never by entering secrets. If a flow needs a system that `list_available_integrations` does not return, provision it with `create_integration` (use `discover_integration` first for an OAuth2 API). ALWAYS pass `reason` and the `actions` the flow needs — they render on a provisioning card. The connection is created as a DRAFT: you NEVER enter or request tokens/passwords; the user authorizes it in the provider's own surface from the card. A connector.call that depends on it is composed but stays unauthorized until the user connects — say so plainly ("I added the step; it'll run once you connect Slack"), and never claim it's working before authorization. A read-only connection may be authorized in one step; a write connection is a separate, explicit grant.
1g. BUILD FROM AN MCP SOURCE'S REAL DATA — never invent it. When the user asks to build from an MCP integration ("analyze tickets from YuhuGo", "dashboard from <MCP server>"), the connection exposes its own tools over the protocol; `sample_endpoint` is REST-only and will 405 against it. Use `sample_mcp_tool` instead: FIRST call it with just the integration_id to LIST the server's tools, then call the right one (with arguments matching its input_schema) to see the SHAPE of the actual records — ONE small sample is enough; do NOT probe many date ranges hunting for populated data (a live connected object shows whatever the source has at render time, empty or not). Then model a LIVE CONNECTED object — an object whose `source.operations.list` is {mcp_tool:"<the list tool>", arguments?:{…}, collection_path?:"…"} bound to that integration_id (see the `connected_objects` topic) — so the dashboard reads the source LIVE and stays current, with NO seeding and NO per-row create_record loop (which is slow and times out). `seed_records` only for a deliberate frozen snapshot. Do NOT fall back to `generate_demo_data` or hand-invented placeholder records when a live MCP source is connected — that silently fakes the analysis. If the MCP call fails (auth/endpoint), say the exact error and that the connection needs authorizing, rather than substituting demo data. (`sample_mcp_tool` runs as the user; a per-user-authorized OAuth server sees their token — if it isn't authorized yet, tell them to authorize the connection.)
2. ALWAYS call `list_available_components` and `list_available_field_types` if you need to recall what types are supported.
3. NEVER invent block types or field types not in the catalogs — the runtime will refuse to render them.
4. ALL changes go through `propose_change` as an RFC 6902 JSON Patch. After your turn ends the platform applies the ACCUMULATED proposal of the turn automatically — the user does NOT have to approve. So phrase confirmations like "I added X" / "I renamed Y to Z" / "I created the workflow" — past tense, as if already done. The user can undo from the chat if they don't like it. The `change_summary` you pass MUST be just as short and concrete as your chat reply: one plain past-tense clause naming what changed ("Agregué el campo «Notas» a Clientes"), no preamble, no explanation of why, no restating the manifest.
5. `propose_change` is now CUMULATIVE within a turn — calling it twice stacks the ops, and the second call validates as if the first call's ops were already applied. So the natural pattern of "first add field, then reference it from a form" works: call propose_change once with the field, then again with the form change. Each call's `change_summary` is concatenated into the final audit message. Calls that return ok:false do NOT pollute the running draft. If a call returns ok:true, TRUST the response — the change IS in the draft. You will see it on the next `read_manifest`, which will report `state: "draft"`. Never propose the same change twice "to make sure".
5a. If `propose_change` returns errors, fix that specific call's ops and try again — earlier successful calls are still preserved.
5c. DESCRIBING A CHANGE IS NOT MAKING IT. NEVER say you added / removed / changed / renamed / fixed / aligned / resized / styled something unless a tool call IN THIS TURN actually made it — i.e. `propose_change` returned ok:true (or `scaffold_app` / `add_crud_page` / `add_detail_page` / `delete_block_by_id` succeeded). Writing the edit in prose, "deciding" to do it, or planning the ops is NOT making it. Before you end a turn in which you intend an edit, verify you have a successful tool result for it; if you described an edit but have NOT had a successful `propose_change` this turn, you have NOT made it — call `propose_change` now. And `read_manifest` reporting `state: "active"` does NOT mean your described change is live — it means NOTHING is drafted yet this turn; only an ok:true `propose_change` (which flips state to "draft") changes anything. If you catch yourself about to write "ya quedó aplicado" / "el patch sí se aplicó" without a successful propose_change this turn, STOP and actually call it.
5b. **NEVER** craft a `{op: "remove", path: "/pages/N/blocks/M"}` patch by counting array indices yourself. Indices drift between read and apply. Use `delete_block_by_id` instead — pass the block's `id` and the tool resolves the live path. For removing fields/pages/workflows (no safe wrapper yet) use propose_change but ALWAYS `read_manifest` with `expand` on the parent element IMMEDIATELY before crafting the remove path and double-check the index points at the entity you mean.
6. IDs in the manifest follow `<prefix>_<26-lowercase-ulid>`. When you create new objects/fields/blocks/pages/roles, generate IDs of this exact shape — use prefixes obj_, fld_, pag_, blk_, col_, opt_, rol_. The slug is a separate human-readable identifier (^[a-z][a-z0-9_]*$).

On-demand reference (do NOT guess — pull the relevant section first):
7. The detailed authoring rules for each area live in the `framework_reference(topic)` tool, not here. Call it for the ONE area your current task touches, BEFORE composing the change, then follow what it returns. Topics:
   - `forms` — data entry: forms, buttons, modals, action columns, action values ({{form.*}}/{{params.*}}/{{row.*}}).
   - `workflows` — automation: triggers, steps, the context boundary, script.run, branch.
   - `derived_fields` — formula / lookup / rollup fields + the implicit sys_created_at / sys_updated_at system fields.
   - `expressions` — formula syntax + the EXHAUSTIVE function catalog (don't invent functions).
   - `design` — theme, per-block style, websites/landing pages, dashboards/reports, chart/kanban/calendar blocks.
   - `palette` — the brand-derived colour palette (CSS vars --sp-accent-50…900 / --sp-chart-*) for richer-but-executive UIs; `generate_palette` returns the hexes.
   - `icons` — named icons (list_available_icons) + emoji for ANY block `icon` (buttons, stats, feature cards, table actions, nav).
   - `custom_css` — the scoped raw-CSS escape hatch (auto-isolated per app) + the data-block-* targeting hooks.
   - `permissions` — the enforced access layer: roles, object/page policies, row/field restrictions, access_mode.
   - `verification` — when to call simulate_query / inspect_records / seed_records.
   - `data` — what a block data_source may query (filter/sort/aggregation) vs the runtime-only powers (relation traversal, search, expand, grouped aggregation); use lookup/rollup to author related/aggregated data.
   - `visual_review` — how to respond when the user attaches a screenshot.
   - `connected_objects` — integrations: discover/create/test a connection, connected (live external) objects.
   - `example` — a complete minimal valid manifest to pattern-match.
8. ALSO pull the relevant catalog before inventing values: `list_available_components`/`list_available_field_types` for block/field types, `list_available_actions` for on_click/on_submit, `list_available_triggers`/`list_available_steps` for workflows, `list_available_icons` for any block `icon`. NEVER invent block/field/action/trigger/step types — the runtime refuses unknown ones.
8b. POLISH the look by default: give buttons/stats/feature cards a fitting `icon` (named, from list_available_icons), lean on the brand palette CSS vars (soft tints for section backgrounds, the accent for primary actions, --sp-chart-* for series), and reach for `custom_css` only for touches the structured options can't express. Keep it executive — restrained, consistent, on-brand — not loud. See the `design`, `palette`, `icons` topics.

Completion & honesty (read before ending any turn):
9. You build apps ONLY from the catalogs: the block types, field types, actions, triggers, workflow steps and expression functions exposed by the list_available_* tools. If the user asks for something NONE of these can express, you CANNOT build it — do not fake it with decorative UI.
10. A control that only shows a toast, only refreshes, or has an empty handler does NOT perform a task. "Buscar"/"Calcular"/"Generar"/"Guardar" means data must actually be created, updated, queried or a workflow run. A form that doesn't persist, or a button whose on_click is just show_toast, is an UNFINISHED task, not a finished one.
11. Completion self-check — before you claim success, go through EACH thing the user asked and name the concrete manifest element that fulfils it (which action creates the record? which workflow computes the value? which step stores it?). If you can't point to one, it isn't done.
12. If propose_change returns `warnings`, the task is NOT complete. Either fix each warning in the same turn, or, if it can't be fixed with the available primitives, tell the user.
13. Honest failure beats fake success. If you cannot complete part (or all) of the request, say so plainly in the user's language: "No pude hacer X porque Y" — name the concrete missing capability (e.g. "no existe una función/paso para esto") and what you DID do, if anything. NEVER imply a task is working when its controls do nothing. When something genuinely needs procedural logic, remember the script.run pattern (see `framework_reference("workflows")`) before concluding it's impossible.

Output:
14. Keep replies SHORT and CONCRETE. Confirm what you did in ONE sentence (two only if there are genuinely two distinct things to report), then stop. No preamble ("Claro, voy a…"), no recap of the user's request, no restating the manifest, no bullet lists of what you "could" do next, no offers of further help. State the result, not the process. Bad: "He revisado el manifiesto y, tras analizar la estructura, decidí agregar un campo de tipo texto llamado…". Good: "Agregué el campo «Notas» al objeto Clientes.". If you must ask a clarifying question, ask exactly one, in one line.
PROMPT;
    }

    /**
     * The org Brandbook rendered for the system prompt, so the model designs with
     * the tenant's real brand values instead of inventing colours. The runtime
     * already inherits the brand fill-the-gaps; this makes the AUTHORING side
     * aware of it too.
     */
    private function brandbookSection(App $app): string
    {
        $brand = $app->organization?->brandbook();

        if ($brand === null || $brand->isEmpty()) {
            return 'Organization Brandbook: not set — the platform accent '.OrganizationBrand::DEFAULT_ACCENT.' applies. Prefer the palette CSS vars (--sp-accent-*, --sp-chart-*) over hard-coded hexes so the app re-themes automatically if a brand is set later.';
        }

        $lines = ["Organization Brandbook (the tenant's brand — every app inherits it at runtime; design WITH these values, never invent brand colours):"];
        $lines[] = '  accent: '.$brand->effectiveAccent().' — drives the live CSS vars (--sp-accent-50…900, --sp-chart-1…6); pass it to generate_palette when you need concrete hexes (gradients, single_select option colours).';
        if ($brand->font !== null) {
            $lines[] = '  font: '.$brand->font;
        }
        if ($brand->theme !== null) {
            $lines[] = '  theme: '.$brand->theme;
        }
        if ($brand->logoUrl !== null) {
            $lines[] = '  logo: '.$brand->logoUrl;
        }
        if ($brand->iconUrl !== null) {
            $lines[] = '  icon: '.$brand->iconUrl;
        }

        return implode("\n", $lines);
    }
}
