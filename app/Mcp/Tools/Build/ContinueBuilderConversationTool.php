<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\User;
use App\Services\Builder\BuilderAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Throwable;

#[Description('Resume an app-builder conversation where it left off: post a message into it and run the builder AI turn synchronously, with the same tools (scaffold, propose_change, …) the in-app builder uses. By default the resulting manifest proposal is auto-applied as a new app version, exactly like the in-app builder. Identify the session by conversation_id (from list_builder_conversations) or by app_slug (its most recent conversation). Builder turns can take 30-120s.')]
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
        $builder = app(BuilderAiService::class);

        try {
            $message = $apply
                ? $builder->sendMessageAndApply($conversation, $text, $user)
                : $builder->sendMessage($conversation, $text);
        } catch (Throwable $e) {
            return Response::error('The builder turn failed: '.$e->getMessage());
        }

        $patch = is_array($message->proposed_patch) ? $message->proposed_patch : null;

        return Response::json(array_filter([
            'conversation_id' => $conversation->id,
            'app_slug' => $app->slug,
            'message_id' => $message->id,
            'reply' => $message->content,
            'status' => $message->status,
            'change_summary' => $message->change_summary,
            'proposed_patch_op_count' => $patch !== null ? count($patch) : null,
            'applied_version_id' => $message->applied_version_id,
            // When a proposal exists but wasn't applied (apply:false, or an
            // apply failure), say so plainly so the caller knows nothing landed.
            'applied' => $message->status === 'applied',
        ], fn ($v) => $v !== null && $v !== ''));
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
                ->description('Auto-apply the resulting manifest proposal as a new version (default true, matching the in-app builder). Pass false to leave it pending for in-app review.'),
        ];
    }
}
