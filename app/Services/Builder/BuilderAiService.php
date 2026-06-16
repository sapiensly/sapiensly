<?php

namespace App\Services\Builder;

use App\Ai\BuilderAgent;
use App\Ai\Tools\Builder\CreateIntegrationTool;
use App\Ai\Tools\Builder\DeleteBlockByIdTool;
use App\Ai\Tools\Builder\DiscoverIntegrationTool;
use App\Ai\Tools\Builder\FrameworkReferenceTool;
use App\Ai\Tools\Builder\InspectRecordsTool;
use App\Ai\Tools\Builder\ListAvailableActionsTool;
use App\Ai\Tools\Builder\ListAvailableComponentsTool;
use App\Ai\Tools\Builder\ListAvailableFieldTypesTool;
use App\Ai\Tools\Builder\ListAvailableStepsTool;
use App\Ai\Tools\Builder\ListAvailableTriggersTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Ai\Tools\Builder\ReadManifestTool;
use App\Ai\Tools\Builder\SampleEndpointTool;
use App\Ai\Tools\Builder\SeedRecordsTool;
use App\Ai\Tools\Builder\SimulateQueryTool;
use App\Ai\Tools\Builder\TestIntegrationConnectionTool;
use App\Ai\Tools\Builder\ValidateManifestTool;
use App\Events\Builder\BuilderActivity;
use App\Events\Builder\BuilderStreamChunk;
use App\Events\Builder\BuilderStreamComplete;
use App\Events\Builder\BuilderStreamError;
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
use App\Services\Integrations\IntegrationCaller;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;
use App\Services\Storage\TenantStorage;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall;

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

        $tools = [
            new ReadManifestTool($app, $this->manifestService, $proposeTool),
            new FrameworkReferenceTool,
            new ListAvailableComponentsTool,
            new ListAvailableFieldTypesTool,
            new ListAvailableActionsTool,
            new ListAvailableTriggersTool,
            new ListAvailableStepsTool,
            new InspectRecordsTool($app),
            new SimulateQueryTool($app, $this->manifestService, $this->records, $proposeTool),
            new ValidateManifestTool($this->validator),
            $proposeTool,
            new DeleteBlockByIdTool($app, $this->manifestService, $proposeTool),
            new SeedRecordsTool($app, $this->manifestService, $this->writer, $conversation->user, $proposeTool),
            new DiscoverIntegrationTool($this->integrationAuthoring),
            new CreateIntegrationTool($this->integrationAuthoring, $conversation->user),
            new TestIntegrationConnectionTool($this->integrationAuthoring, $conversation->user),
            new SampleEndpointTool(app(IntegrationCaller::class), $conversation->user),
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

            // Resolve the builder's primary model; on an LLM/provider error,
            // withFallback re-runs the prompt with the configured fallback.
            $response = $this->aiDefaults->withFallback('builder', function (string $model) use ($sdkAgent, $promptText, $user, $conversation) {
                $provider = $user !== null
                    ? $this->providers->resolveProvider($model, $user)
                    : Lab::Anthropic;

                Log::info('Builder AI calling provider', [
                    'conversation_id' => $conversation->id,
                    'provider' => $provider->value,
                    'model' => $model,
                ]);

                return $sdkAgent->prompt($promptText, provider: $provider, model: $model);
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
                'content' => 'Sorry — the AI request failed: '.$e->getMessage(),
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
            'status' => $proposal !== null ? 'pending' : 'none',
        ]);
    }

    /**
     * Async variant invoked from RunBuilderAiJob. The user message has
     * already been persisted by the controller and a placeholder assistant
     * message is passed in with status='streaming'. We stream Claude's
     * response, broadcast deltas, then finalize the placeholder + broadcast
     * BuilderStreamComplete.
     */
    public function streamMessage(BuilderMessage $placeholder, string $userText, ?string $attachmentPath = null, ?string $attachmentDisk = null, ?string $modelOverride = null): BuilderMessage
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

        $tools = [
            new ReadManifestTool($app, $this->manifestService, $proposeTool),
            new FrameworkReferenceTool,
            new ListAvailableComponentsTool,
            new ListAvailableFieldTypesTool,
            new ListAvailableActionsTool,
            new ListAvailableTriggersTool,
            new ListAvailableStepsTool,
            new InspectRecordsTool($app),
            new SimulateQueryTool($app, $this->manifestService, $this->records, $proposeTool),
            new ValidateManifestTool($this->validator),
            $proposeTool,
            new DeleteBlockByIdTool($app, $this->manifestService, $proposeTool),
            new SeedRecordsTool($app, $this->manifestService, $this->writer, $conversation->user, $proposeTool),
            new DiscoverIntegrationTool($this->integrationAuthoring),
            new CreateIntegrationTool($this->integrationAuthoring, $conversation->user),
            new TestIntegrationConnectionTool($this->integrationAuthoring, $conversation->user),
            new SampleEndpointTool(app(IntegrationCaller::class), $conversation->user),
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

        try {
            $user = $conversation->user;
            if ($user !== null) {
                $this->providers->applyRuntimeConfig($user);
                $provider = $this->providers->resolveProvider($resolvedModel, $user);
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

            $proposal = $proposeTool->lastProposal();
            $appliedVersionId = null;
            $finalStatus = 'none';

            if ($proposal !== null) {
                try {
                    $newVersion = $this->manifestService->applyPatch(
                        $app,
                        $proposal['patch'],
                        $conversation->user,
                        $proposal['summary'] ?? 'Builder AI change',
                    );
                    $appliedVersionId = $newVersion->id;
                    $finalStatus = 'applied';
                } catch (\Throwable $e) {
                    // The proposal validated inside the tool but failed when
                    // re-applied at the end — likely a race against a parallel
                    // edit. Record the patch so the user sees what was tried
                    // and can re-prompt.
                    Log::warning('Builder AI auto-apply failed (proposal kept on message)', [
                        'conversation_id' => $conversation->id,
                        'message_id' => $placeholder->id,
                        'error' => $e->getMessage(),
                    ]);
                    $finalStatus = 'none';
                }
            }

            $placeholder->update([
                'content' => $buffer,
                'proposed_patch' => $proposal['patch'] ?? null,
                'change_summary' => $proposal['summary'] ?? null,
                'status' => $finalStatus,
                'applied_version_id' => $appliedVersionId,
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
            // when the original error was a column-length overflow).
            $errMsg = mb_substr($providerError ?? $e->getMessage(), 0, 1500);
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
        return <<<PROMPT
You are the Builder AI inside Sapiensly. Your job is to help the tenant edit a low-code App by modifying its JSON manifest.

App details:
  id: {$app->id}
  slug: {$app->slug}
  name: {$app->name}

Language:
0. ALWAYS reply in the same language as the most recent user message. If the user writes in Spanish, your assistant turn (including confirmations, error messages, clarifications and the `change_summary` you pass to propose_change) is in Spanish. If they switch to English mid-conversation, switch with them. Don't mix languages within a single reply. Default to the user's language even if your internal reasoning was in English.

Rules of engagement:
1. ALWAYS call `read_manifest` first to see the current structure before proposing edits. By default it returns `{state, op_count, note, summary}` — a COMPACT structural map (objects→fields with id/slug/type, pages→blocks with id/type, workflows, settings keys, agent on/off), NOT full property values. That summary is enough to locate what you need. To edit a specific object/page/workflow, call `read_manifest` again with `expand: "<id>"` to get that ONE element's full definition (returned as `element`); only expand what you're about to change, never the whole manifest element-by-element. After you've made one or more successful `propose_change` calls, `state` flips to "draft" and the summary/element reflect the in-progress draft (with your ops already applied) — DO NOT re-propose what's already there. If `propose_change` returned ok:true, the change is in the draft, even if your previous read showed it absent. Re-reading is only useful between calls that depend on the new structure (e.g. you added a field and now need the field id to reference from a form).
2. ALWAYS call `list_available_components` and `list_available_field_types` if you need to recall what types are supported.
3. NEVER invent block types or field types not in the catalogs — the runtime will refuse to render them.
4. ALL changes go through `propose_change` as an RFC 6902 JSON Patch. After your turn ends the platform applies the ACCUMULATED proposal of the turn automatically — the user does NOT have to approve. So phrase confirmations like "I added X" / "I renamed Y to Z" / "I created the workflow" — past tense, as if already done. The user can undo from the chat if they don't like it. The `change_summary` you pass MUST be just as short and concrete as your chat reply: one plain past-tense clause naming what changed ("Agregué el campo «Notas» a Clientes"), no preamble, no explanation of why, no restating the manifest.
5. `propose_change` is now CUMULATIVE within a turn — calling it twice stacks the ops, and the second call validates as if the first call's ops were already applied. So the natural pattern of "first add field, then reference it from a form" works: call propose_change once with the field, then again with the form change. Each call's `change_summary` is concatenated into the final audit message. Calls that return ok:false do NOT pollute the running draft. If a call returns ok:true, TRUST the response — the change IS in the draft. You will see it on the next `read_manifest`, which will report `state: "draft"`. Never propose the same change twice "to make sure".
5a. If `propose_change` returns errors, fix that specific call's ops and try again — earlier successful calls are still preserved.
5b. **NEVER** craft a `{op: "remove", path: "/pages/N/blocks/M"}` patch by counting array indices yourself. Indices drift between read and apply. Use `delete_block_by_id` instead — pass the block's `id` and the tool resolves the live path. For removing fields/pages/workflows (no safe wrapper yet) use propose_change but ALWAYS `read_manifest` with `expand` on the parent element IMMEDIATELY before crafting the remove path and double-check the index points at the entity you mean.
6. IDs in the manifest follow `<prefix>_<26-lowercase-ulid>`. When you create new objects/fields/blocks/pages/roles, generate IDs of this exact shape — use prefixes obj_, fld_, pag_, blk_, col_, opt_, rol_. The slug is a separate human-readable identifier (^[a-z][a-z0-9_]*$).

On-demand reference (do NOT guess — pull the relevant section first):
7. The detailed authoring rules for each area live in the `framework_reference(topic)` tool, not here. Call it for the ONE area your current task touches, BEFORE composing the change, then follow what it returns. Topics:
   - `forms` — data entry: forms, buttons, modals, action columns, action values ({{form.*}}/{{params.*}}/{{row.*}}).
   - `workflows` — automation: triggers, steps, the context boundary, script.run, branch.
   - `derived_fields` — formula / lookup / rollup fields + the implicit sys_created_at / sys_updated_at system fields.
   - `expressions` — formula syntax + the EXHAUSTIVE function catalog (don't invent functions).
   - `design` — theme, per-block style, websites/landing pages, dashboards/reports, chart/kanban/calendar blocks.
   - `verification` — when to call simulate_query / inspect_records / seed_records.
   - `visual_review` — how to respond when the user attaches a screenshot.
   - `connected_objects` — integrations: discover/create/test a connection, connected (live external) objects.
   - `example` — a complete minimal valid manifest to pattern-match.
8. ALSO pull the relevant catalog before inventing values: `list_available_components`/`list_available_field_types` for block/field types, `list_available_actions` for on_click/on_submit, `list_available_triggers`/`list_available_steps` for workflows. NEVER invent block/field/action/trigger/step types — the runtime refuses unknown ones.

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
}
