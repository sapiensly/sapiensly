<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Retrieve one app-builder conversation with its full transcript (user turns + the assistant's replies, each turn's change summary, proposal status and applied version) — to debug what was built or to pick up where a session left off. Identify it by conversation_id (from list_builder_conversations), or pass app_slug to get that app's most recent conversation.")]
class GetBuilderConversationTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'string'],
            'app_slug' => ['nullable', 'string'],
            'include_patches' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (($validated['conversation_id'] ?? null) === null && ($validated['app_slug'] ?? null) === null) {
            return Response::error('Provide either conversation_id or app_slug.');
        }

        [$conversation, $app, $error] = $this->resolve($validated, $user);
        if ($error !== null) {
            return Response::error($error);
        }

        $includePatches = (bool) ($validated['include_patches'] ?? false);
        $limit = $validated['limit'] ?? 200;
        $order = $validated['order'] ?? 'asc';

        // Take the most recent $limit messages, then present oldest-first unless
        // the caller asked otherwise — so a long session returns its latest turns.
        $messages = $conversation->messages()
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($order === 'asc') {
            $messages = $messages->reverse()->values();
        }

        return Response::json([
            'conversation' => [
                'conversation_id' => $conversation->id,
                'app_slug' => $app->slug,
                'status' => $conversation->status,
                'user_id' => $conversation->user_id,
                'created_at' => $conversation->created_at?->toIso8601String(),
                // The cross-turn build plan (steps + status), so a debugger can
                // see exactly where a multi-step build stalled or left off.
                'build_plan' => $conversation->build_plan,
            ],
            'messages' => $messages->map(function (BuilderMessage $m) use ($includePatches): array {
                $patch = is_array($m->proposed_patch) ? $m->proposed_patch : null;

                return array_filter([
                    'message_id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'status' => $m->status,
                    'change_summary' => $m->change_summary,
                    // Always report whether (and how big) a manifest proposal was;
                    // ship the full RFC 6902 ops only when asked (they can be large).
                    'proposed_patch_op_count' => $patch !== null ? count($patch) : null,
                    'proposed_patch' => ($includePatches && $patch !== null) ? $patch : null,
                    'plan' => $m->plan,
                    'integration_proposal' => $m->integration_proposal,
                    'applied_version_id' => $m->applied_version_id,
                    'has_attachment' => $m->attachment_path !== null ? true : null,
                    // Per-turn tool timeline ({event: call|result, tool,
                    // model_seconds|tool_seconds, t}) persisted live during the
                    // turn — shows where a slow build spent its wall-clock, even
                    // if the turn later timed out or was killed.
                    'timeline' => is_array($m->timeline) && $m->timeline !== [] ? $m->timeline : null,
                    'created_at' => $m->created_at?->toIso8601String(),
                ], fn ($v) => $v !== null && $v !== '');
            })->values(),
            'message_count' => $messages->count(),
        ]);
    }

    /**
     * Resolve the conversation + its (visible) app from the input, or an error
     * string. By conversation_id: load it, then confirm its app is visible to
     * the caller. By app_slug: take that app's most recent conversation (active
     * first).
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: BuilderConversation|null, 1: App|null, 2: string|null}
     */
    private function resolve(array $validated, User $user): array
    {
        if (($validated['conversation_id'] ?? null) !== null) {
            $conversation = BuilderConversation::query()->find($validated['conversation_id']);
            if ($conversation === null) {
                return [null, null, "No builder conversation '{$validated['conversation_id']}' is visible to you."];
            }

            $app = App::query()->forAccountContext($user)->where('id', $conversation->app_id)->first();
            if ($app === null) {
                return [null, null, "No builder conversation '{$validated['conversation_id']}' is visible to you."];
            }

            return [$conversation, $app, null];
        }

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return [null, null, "No app named '{$validated['app_slug']}' is visible to you."];
        }

        $conversation = BuilderConversation::query()
            ->where('app_id', $app->id)
            ->orderByRaw("status = 'active' DESC")
            ->orderByDesc('created_at')
            ->first();

        if ($conversation === null) {
            return [null, null, "App '{$app->slug}' has no builder conversations yet."];
        }

        return [$conversation, $app, null];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->string()
                ->description('The id of the builder conversation to retrieve (from list_builder_conversations).'),
            'app_slug' => $schema->string()
                ->description("Instead of an id, pass an app slug to get that app's most recent builder conversation."),
            'include_patches' => $schema->boolean()
                ->description('Include the full RFC 6902 proposal ops on each turn (default false; they can be large — op counts are always returned).'),
            'limit' => $schema->integer()->description('Max messages to return — the most recent ones (default 200, max 500).'),
            'order' => $schema->string()->enum(['asc', 'desc'])
                ->description('Order of returned messages: asc = oldest first (default), desc = newest first.'),
        ];
    }
}
