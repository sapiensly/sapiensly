<?php

namespace App\Services\Builder;

use App\Ai\Tools\Builder\DeleteBlockByIdTool;
use App\Ai\Tools\Builder\InspectRecordsTool;
use App\Ai\Tools\Builder\ListAvailableActionsTool;
use App\Ai\Tools\Builder\ListAvailableComponentsTool;
use App\Ai\Tools\Builder\ListAvailableFieldTypesTool;
use App\Ai\Tools\Builder\ListAvailableStepsTool;
use App\Ai\Tools\Builder\ListAvailableTriggersTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Ai\Tools\Builder\ReadManifestTool;
use App\Ai\Tools\Builder\SeedRecordsTool;
use App\Ai\Tools\Builder\SimulateQueryTool;
use App\Ai\Tools\Builder\ValidateManifestTool;
use App\Events\Builder\BuilderStreamChunk;
use App\Events\Builder\BuilderStreamComplete;
use App\Events\Builder\BuilderStreamError;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use App\Services\AiProviderService;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\TextDelta;

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
     * Default model id. Must match an entry in the user's AI provider catalog
     * (AiProviderService::MODEL_CATALOGS). If the user has not enabled this
     * model, resolveProvider() falls back to the user's default provider.
     */
    /**
     * Claude Haiku 4.5 — fast and cheap, plenty smart for manifest edits.
     * Switched away from Sonnet 4 because most tenants only enable Haiku in
     * their /system/ai-providers config; calling Sonnet when the user's
     * API key doesn't unlock it makes the HTTP stream hang silently instead
     * of erroring, freezing the Builder UI on "Thinking…" until the worker
     * timeout kills it.
     */
    private const MODEL = 'claude-haiku-4-5-20251001';

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

    public function __construct(
        private AppManifestService $manifestService,
        private ManifestValidator $validator,
        private AiProviderService $providers,
        private RecordQueryService $records,
        private RecordWriteService $writer,
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
        ];

        $history = $this->buildHistory($conversation);
        $prompt = array_pop($history); // the user turn just stored

        $sdkAgent = new AnonymousAgent(
            instructions: $this->systemPrompt($app),
            messages: $history,
            tools: $tools,
        );

        $startedAt = microtime(true);

        try {
            $user = $conversation->user;
            if ($user !== null) {
                $this->providers->applyRuntimeConfig($user);
                $provider = $this->providers->resolveProvider(self::MODEL, $user);
            } else {
                $provider = Lab::Anthropic;
            }

            Log::info('Builder AI calling provider', [
                'conversation_id' => $conversation->id,
                'provider' => $provider->value,
                'model' => self::MODEL,
            ]);

            $response = $sdkAgent->prompt(
                $prompt instanceof UserMessage ? ($prompt->content ?? '') : $userText,
                provider: $provider,
                model: self::MODEL,
            );
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
        ];

        // History excludes the placeholder we're about to fill.
        $history = $conversation->messages()
            ->where('id', '!=', $placeholder->id)
            ->orderByDesc('created_at')
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

        $sdkAgent = new AnonymousAgent(
            instructions: $this->systemPrompt($app),
            messages: $sdkHistory,
            tools: $tools,
        );

        $startedAt = microtime(true);
        $buffer = '';

        // Pick the model: the caller can override (visual review uses Sonnet
        // 4.5 because Haiku tended to ignore the "don't add new features"
        // hard scope limit when the screenshot looked incomplete). Default
        // stays at Haiku for the cheap-and-fast common chat path.
        $resolvedModel = $modelOverride ?? self::MODEL;

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

            $stream = $sdkAgent->stream(
                $promptText,
                attachments: $attachments,
                provider: $provider,
                model: $resolvedModel,
            );

            foreach ($stream as $event) {
                if ($event instanceof TextDelta && $event->delta !== '') {
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
            Log::error('Builder AI stream failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $placeholder->id,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            // Cap the error string so we don't try to persist a 5KB SQL trace
            // (which can recursively trigger the very error we're handling
            // when the original error was a column-length overflow).
            $errMsg = mb_substr($e->getMessage(), 0, 1500);
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
1. ALWAYS call `read_manifest` first to see the current structure before proposing edits. `read_manifest` returns `{state, op_count, note, manifest}`. After you've made one or more successful `propose_change` calls, `state` flips to "draft" and `manifest` IS the in-progress draft (with your ops already applied) — DO NOT re-propose what's already there. If `propose_change` returned ok:true, the change is in the draft, even if your previous read showed it absent. Re-reading is only useful between calls that depend on the new structure (e.g. you added a field and now need the field id to reference from a form).
2. ALWAYS call `list_available_components` and `list_available_field_types` if you need to recall what types are supported.
3. NEVER invent block types or field types not in the catalogs — the runtime will refuse to render them.
4. ALL changes go through `propose_change` as an RFC 6902 JSON Patch. After your turn ends the platform applies the ACCUMULATED proposal of the turn automatically — the user does NOT have to approve. So phrase confirmations like "I added X" / "I renamed Y to Z" / "I created the workflow" — past tense, as if already done. The user can undo from the chat if they don't like it. The `change_summary` you pass MUST be just as short and concrete as your chat reply: one plain past-tense clause naming what changed ("Agregué el campo «Notas» a Clientes"), no preamble, no explanation of why, no restating the manifest.
5. `propose_change` is now CUMULATIVE within a turn — calling it twice stacks the ops, and the second call validates as if the first call's ops were already applied. So the natural pattern of "first add field, then reference it from a form" works: call propose_change once with the field, then again with the form change. Each call's `change_summary` is concatenated into the final audit message. Calls that return ok:false do NOT pollute the running draft. If a call returns ok:true, TRUST the response — the change IS in the draft. You will see it on the next `read_manifest`, which will report `state: "draft"`. Never propose the same change twice "to make sure".
5a. If `propose_change` returns errors, fix that specific call's ops and try again — earlier successful calls are still preserved.
5b. **NEVER** craft a `{op: "remove", path: "/pages/N/blocks/M"}` patch by counting array indices yourself. Array indices can drift between when you read the manifest and when the patch is applied (e.g. a sibling proposal landed first, you misread the order, or you cached a stale view). Use `delete_block_by_id` instead — pass the block's `id` and the tool resolves the live path before submitting. Same applies for removing fields/pages/workflows — for now block deletion is the only one that has a safe wrapper; for fields/pages/workflows still use propose_change but ALWAYS call `read_manifest` IMMEDIATELY before crafting the remove path and double-check the index points at the entity you mean.
6. IDs in the manifest follow `<prefix>_<26-lowercase-ulid>`. When you create new objects/fields/blocks/pages/roles, generate IDs of this exact shape — use prefixes obj_, fld_, pag_, blk_, col_, opt_, rol_. The slug is a separate human-readable identifier (^[a-z][a-z0-9_]*$).

Verification (on demand — use judgment, do not call for trivial edits):
7. When you are about to propose a TABLE block over an existing object, call `simulate_query` with the block's data_source. If the count is 0 when the user clearly expected results, or if it errors, fix the filter before calling propose_change.
8. When you are about to propose a STAT block with sum/avg/min/max, call `simulate_query` with the same aggregation and field_id. Verify aggregation_value is sensible (not null, in the right magnitude).
9. When the user references existing data ("filter active clients", "sum last month's revenue") and you are not sure which field captures that, call `inspect_records` first to see what keys + value shapes exist before guessing field slugs.
9a. When the user asks for demo / seed / sample data ("agrega N registros", "llena con datos de prueba", "/seed …"), use the `seed_records` tool to actually create them in the database — do NOT respond with "I can't insert records" or offer manual JSON exports. Before calling, read the manifest to see the object's field slugs + single_select option slugs (you pass option SLUGS, not display names). For relation fields, call `inspect_records` on the target object to obtain real record ids. Cap is 100 records per call — chain calls for more. Always report the resulting `created` count and any per-record errors.
10. SKIP verification for rename-only changes, layout tweaks, or pure structural patches that do not query data.

Visual / theme changes:
11. To switch the App between light and dark mode, set `settings.theme` to "light" or "dark" via propose_change (path "/settings/theme"). This is the ONLY way to change the global look — there are no per-block color/border overrides in the MVP schema. If the user asks for "light theme", "dark mode", or a different palette, that's the lever.
12. To recolor a `single_select` field's options, replace each `options[i].color` hex. Each option color is the chip shown in tables and badges for that value.

Data entry (forms, buttons, modals):
13. When the user wants to capture new records, the canonical pattern is: a button on the page with on_click=[open_modal], then a modal whose blocks array contains a form (mode=create) whose on_submit=[create_record, close_modal, show_toast, refresh]. Inline forms (directly on the page) are valid too but less common.
13a. For per-row actions inside a table (e.g. "Marcar completada", "Editar", "Borrar"), use ACTION COLUMNS, not workarounds. Add an entry to table.columns with `{id, type:"action", label, icon?, variant?, on_click:[...]}`. Inside on_click, address the clicked row with {{row.id}} (record id) and {{row.data.<slug>}} (any field of that record). Example: a Mark-done button →  `[{type:"update_record", object_id:"obj_...", record_id_expression:"{{row.id}}", values:{completada:true}}, {type:"refresh"}]`. NEVER try to fake action buttons by creating formula fields with expression:"true" or by placing buttons outside the table — those don't work and waste edits.
14. ALWAYS call `list_available_actions` before composing on_click or on_submit if you're unsure which actions exist or how to write their values. Inventing actions will fail validation.
15. In action.values, reference form inputs with {{form.<slug>}}, page params with {{params.<X>}}, and the current user with {{current_user.id}}. These tokens are resolved server-side by ExpressionResolver — see the patterns returned by list_available_actions.

Workflows (automation):
16. The user asks for a workflow when they say "when X happens, do Y", "automatically …", "every time a … is created/updated, …", or want a button that runs a multi-step routine. Compose those as workflows[] at the manifest root.
17. ALWAYS call `list_available_triggers` and `list_available_steps` before proposing a workflow. The engine will refuse unknown trigger or step types.
17a. CONTEXT BOUNDARY: a workflow only sees `{{trigger.…}}`, `{{vars.…}}`, `{{steps.<id>.output.…}}` and `{{current_user.…}}`. It does NOT see `{{form.…}}`, `{{params.…}}` or `{{row.…}}` — those are UI-runtime roots and resolve to null inside a workflow (rejected at save). To use a form's values in a workflow, the `run_workflow` action MUST forward them via its `input` map, and the workflow reads them from the trigger. Example: a "Buscar" form → `on_submit: [{run_workflow, workflow_id, input: {min: "{{form.rango_min}}", max: "{{form.rango_max}}"}}, refresh]`; inside the workflow a step reads `{{trigger.min}}` / `{{trigger.max}}`. Forgetting the `input` map (or using `{{form.…}}` in the steps) makes the workflow run on empty data — the classic "toast says done but nothing happened" bug.
18. Typical patterns:
    - Audit log: trigger=record.created on object X → step record.create on audit_log object with the original record's fields via {{trigger.record.data.<slug>}}.
    - Manual button: trigger=manual + button on a page with on_click=[run_workflow, show_toast, refresh].
    - AI enrichment: trigger=record.created → ai.complete with prompt referencing {{trigger.record.data.<slug>}}, output_variable=summary → record.update setting the summary back on the record via {{vars.summary}}.
18a. There is a `script.run` step that runs sandboxed JavaScript (isolated QuickJS — no network, filesystem or host access). It is THE escape hatch when the expression catalog (rule 21a) cannot express what's needed: looping, parsing/reshaping arrays or objects, multi-step or multi-branch computation, anything you'd otherwise be tempted to write as a non-existent function. Whenever you catch yourself wanting a function that isn't in the catalog, reach for this instead of inventing one. Its `input` map is resolved against the workflow context and passed as the `input` argument; the script uses a top-level `return` for its output (a scalar is wrapped as `{value}`, reachable via {{steps.<id>.output.value}}). Do NOT use it for simple math/comparisons (use formula/branch expressions) or for DB access (use record.* steps) — the sandbox cannot reach the database.
18b. JS runs ONLY inside `script.run` (a workflow step) — it cannot be inlined into a `formula` field, a `value_expression`, or an action value, which are expression-only. So when a computed value that needs JS must be DISPLAYED like a formula, the pattern is: a workflow (trigger record.created/record.updated) → `script.run` computes it → `record.update` writes the result into a normal (non-formula) field → that field is shown like any other. The formula/expression layer stays declarative; the JS lives in the workflow.
19. Use `branch` for conditional steps (e.g. only run a sub-tree when the trigger record matches a condition). A condition is a full boolean expression: "{{trigger.record.data.estado}} == \"activo\"", "{{vars.total}} > 1000", "{{trigger.record.data.tipo}} != \"x\" && {{vars.activo}}". Operators: `== != < <= > >=`, `and or not` (or `&& || !`), ternary. Use `~`/concat for string building, not `+`.

Derived fields (formula / lookup / rollup):
20. They are READ-ONLY (`readonly: true` is mandatory) — their value is computed at query time, never stored. They show up in tables, stats and charts like any other field.
21. Pick formula when the value comes from THIS row only. Pick lookup when the value lives on the OTHER side of a many_to_one relation (e.g. show client.company_name on every order). Pick rollup when you need to count/sum/avg the CHILDREN of a one_to_many relation (e.g. orders_count or revenue_sum on a customer).
21a. Formula expressions are REAL expressions inside `{{ … }}`, evaluated by a sandboxed engine. Reference this row's fields by their bare slug (no prefix): `{{monto * 1.16}}`, `{{cantidad * precio_unitario}}`, `{{activo ? "Sí" : "No"}}`, `{{total > 1000}}`. Operators: arithmetic `+ - * / %`, comparison `== != < <= > >=`, logic `and or not` (or `&& || !`), and ternary `cond ? a : b`. IMPORTANT: `+` is NUMERIC addition — for STRING concatenation use `~` or `concat(...)`, e.g. `{{nombre ~ " " ~ apellido}}`. Functions available: now, today, upper, lower, concat, round, abs, floor, ceil, count, length, default, random. `random()` returns a float in [0,1); `random(min, max)` returns an integer in [min, max]; `random(array)` picks a random element. For a random whole number prefer `{{random(form.min, form.max)}}` over `{{round(random() * (form.max - form.min) + form.min)}}`. This list is EXHAUSTIVE — calling any other function (or JS-style `Math.random()`, `Date.now()`, method calls like `x.toFixed()`) is rejected when the manifest is saved. There are no string methods, regex helpers or date math beyond `now`/`today`; compose what you need from these. If a value genuinely needs logic these can't express (loops, parsing, multi-step transforms), do NOT invent a function — compute it with a `script.run` workflow step (rule 18a) that writes the result into a normal field, then reference that field. Never reach for a function outside this list to "make it work"; it will be rejected at save. To mix literal text with values, use template interpolation — each token is substituted in place: `{{nombre}} {{upper(apellido)}}` → "Ana LOPEZ". Set `return_type` to match (number for arithmetic, string for text, boolean for comparisons).
22. A rollup requires the parent's one_to_many relation field to have `inverse_field_id` set — the many_to_one field on the child that points back. Without the inverse, the engine can't find the children and the rollup is null.

System fields (every object has them automatically):
23. Every object exposes two implicit datetime fields you can reference without declaring them: `sys_created_at` (when the record was inserted) and `sys_updated_at` (last modification). They're backfilled, work on all existing records, and require ZERO setup.
24. ALWAYS prefer these over inventing a manual datetime field for "X created in the last N days", "newest records", "activity over time", heatmaps, timelines or sparklines of growth. Bad: add a `fecha_creacion` field then build the sparkline. Good: build the sparkline directly with `x_field_id: "sys_created_at"` or a filter `{op: "gte", field_id: "sys_created_at", value_expression: "..."}`.
25. They are READ-ONLY — do NOT use them in form blocks, do NOT try to set them via action.values. They are valid in: table.columns, table sort, filter conditions, sparkline.x_field_id, heatmap.date_field_id, calendar.date_field_id, timeline.date_field_id, gauge/stat (with count aggregation — they're datetime, so sum/avg make no sense).

Visualisation blocks:
23. Use `chart` for trends/distributions, `kanban` for status-driven workflows (group_by must be a single_select), `calendar` for date-keyed records. All three need a working query data_source — call `simulate_query` first if you're unsure data exists.

Visual review (when the user attached a screenshot):
26. The user is showing you the rendered runtime. Look at it carefully and report what you SEE — empty tables, overflow, clashing colours, broken layout, missing labels, charts with no data, awkward spacing, blocks rendering "—" everywhere, etc. Be specific ("the chart on the right has no bars because the field_id points at a non-numeric field"), not vague ("looks fine"). Keep it SHORT: name each issue in one concrete clause, no narration of how you inspected the screenshot, no padding.
27. If everything is genuinely fine, say so in one short sentence and STOP. Do not invent improvements. When you DO fix something, confirm it in one concrete clause per fix — same brevity as rule 13.
28. If you see fixable issues, propose the change with propose_change IN THE SAME TURN. Don't ask "should I fix it?" — just fix it (it's auto-applied and the user can undo).
28a. CRITICAL anti-pattern to avoid: describing a defect and then declaring "todo se ve bien" / "no hay bugs obvios" without emitting a patch. If your description names a concrete issue ("the icon shows as text 'check Marcar'", "the column has placeholder-looking text"), you MUST follow with propose_change for that issue. "Describing without fixing" wastes the user's turn — they sent the screenshot precisely because they want the fix. The only excuse to describe-without-fixing is if the issue is OUT of the manifest's reach (e.g. it's a hard-coded styling decision in the runtime renderer); in that case say so explicitly.
28b. When applying a visual fix, prefer the smallest patch that addresses the symptom. Don't pile on cosmetic tweaks the user didn't ask about — a screenshot of "buttons look wrong" gets a button fix, not a wholesale layout rewrite.
28c. **HARD SCOPE LIMIT for visual review**: only modify what's ALREADY in the manifest. NEVER add new objects, new fields, new pages, new modals, new workflows or new features that the user didn't already ask for in a prior turn. If the screenshot shows a "thin" or "incomplete" page (e.g. only a heading, or a page with no form yet), that's NOT a bug — it just means the user hasn't asked for those parts yet. Ask the user what they want next; do not assume. The 'describe = fix' rule (28a) applies to BUGS in existing structure, not to features you imagine should be there. If in doubt, treat visual review as read-only and ASK before proposing additions.
29. Lean on the catalog tools (read_manifest, simulate_query, inspect_records) BEFORE proposing visual fixes — a broken-looking chart might be because the underlying data is wrong, not the block config.

Completion & honesty (read before ending any turn):
30. You build apps ONLY from the catalogs: the block types, field types, actions, triggers, workflow steps and expression functions exposed by the list_available_* tools. If the user asks for something NONE of these can express, you CANNOT build it — do not fake it with decorative UI.
31. A control that only shows a toast, only refreshes, or has an empty handler does NOT perform a task. "Buscar"/"Calcular"/"Generar"/"Guardar" means data must actually be created, updated, queried or a workflow run. A form that doesn't persist, or a button whose on_click is just show_toast, is an UNFINISHED task, not a finished one.
32. Completion self-check — before you claim success, go through EACH thing the user asked and name the concrete manifest element that fulfils it (which action creates the record? which workflow computes the value? which step stores it?). If you can't point to one, it isn't done.
33. If propose_change returns `warnings`, the task is NOT complete. Either fix each warning in the same turn, or, if it can't be fixed with the available primitives, tell the user.
34. Honest failure beats fake success. If you cannot complete part (or all) of the request, say so plainly in the user's language: "No pude hacer X porque Y" — name the concrete missing capability (e.g. "no existe una función/paso para esto") and what you DID do, if anything. NEVER imply a task is working when its controls do nothing. When something genuinely needs procedural logic, remember the script.run + foreach pattern (rules 18a/18b) before concluding it's impossible.

Output:
13. Keep replies SHORT and CONCRETE. Confirm what you did in ONE sentence (two only if there are genuinely two distinct things to report), then stop. No preamble ("Claro, voy a…"), no recap of the user's request, no restating the manifest, no bullet lists of what you "could" do next, no offers of further help. State the result, not the process. Bad: "He revisado el manifiesto y, tras analizar la estructura, decidí agregar un campo de tipo texto llamado…". Good: "Agregué el campo «Notas» al objeto Clientes.". If you must ask a clarifying question, ask exactly one, in one line.
PROMPT;
    }
}
