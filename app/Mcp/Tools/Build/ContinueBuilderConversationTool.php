<?php

namespace App\Mcp\Tools\Build;

use App\Jobs\RunBuilderAiJob;
use App\Mcp\Tools\SapiensTool;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\BuilderCancellation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Resume an app-builder conversation: post a message and run a builder AI turn — the same tools (scaffold, propose_change, …) the in-app builder uses — ASYNCHRONOUSLY in the background queue. Returns immediately with the placeholder message_id while the turn runs (30-120s); a synchronous run would exceed the MCP transport timeout and die mid-turn. POLL get_builder_conversation (by conversation_id) until the assistant message leaves status="streaming": then read its reply, change_summary and applied version. By default the proposal auto-applies as a new app version (reversible via rollback_app); pass apply:false to leave it pending for in-app review. Identify the session by conversation_id or app_slug (its most recent conversation, or a fresh one).')]
class ContinueBuilderConversationTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'string'],
            'app_slug' => ['nullable', 'string'],
            'message' => ['required', 'string', 'max:5000'],
            'apply' => ['nullable', 'boolean'],
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

        $text = trim($validated['message']);
        if ($text === '') {
            return Response::error('The message is empty.');
        }

        $apply = (bool) ($validated['apply'] ?? true);

        // Persist the user turn + an assistant placeholder up front, then run the
        // turn in the background queue — exactly like the in-app builder. A
        // synchronous run here would outlive the MCP transport timeout and get
        // killed mid-turn, freezing the placeholder in `streaming` and applying
        // nothing (the failure mode this tool used to hit).
        // A new user turn re-arms the machinery: clear any standing stop flag.
        app(BuilderCancellation::class)->clear($conversation);

        BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $text,
            'status' => 'none',
        ]);
        $placeholder = BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'status' => 'streaming',
        ]);

        RunBuilderAiJob::dispatch(
            $placeholder->id,
            $text,
            null,
            null,
            null,
            0,
            $apply,
        );

        return Response::json([
            'conversation_id' => $conversation->id,
            'app_slug' => $app->slug,
            'message_id' => $placeholder->id,
            'status' => 'streaming',
            'apply' => $apply,
            'message' => 'Builder turn started in the background. Poll get_builder_conversation'
                ." (conversation_id='{$conversation->id}') until this message leaves status='streaming' —"
                .' then read its reply, change_summary and applied_version_id. Turns take 30-120s.',
        ]);
    }

    /**
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

        // Resuming with no prior conversation just starts a fresh one for the app.
        if ($conversation === null) {
            $conversation = app(BuilderAiService::class)->startConversation($app, $user);
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
                ->description('The id of the builder conversation to continue (from list_builder_conversations).'),
            'app_slug' => $schema->string()
                ->description("Instead of an id, pass an app slug to continue that app's most recent builder conversation (or start one if none exists)."),
            'message' => $schema->string()
                ->description('The instruction to send into the builder, as if typed in the in-app builder chat.')
                ->required(),
            'apply' => $schema->boolean()
                ->description('Auto-apply the resulting manifest proposal as a new version (default true, matching the in-app builder; reversible via rollback_app). Pass false to leave the proposal pending for in-app review instead. The turn runs async either way.'),
        ];
    }
}
