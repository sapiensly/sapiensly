<?php

namespace App\Services\Express;

use App\Enums\Visibility;
use App\Events\Chat\ChatStreamComplete;
use App\Jobs\ExpressAppJob;
use App\Jobs\ExpressDashboardJob;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\ChatMessage;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Builder\BuilderCancellation;
use App\Services\Manifest\AppManifestService;
use App\Support\Apps\AppNaming;

/**
 * Shared entry point for launching an L4 Express dashboard build. Extracted from
 * AppBuilderController::startExpressRun so every surface that can start a build
 * — the Builder endpoint, the G-0 chat autoroute, the MCP tool — persists the
 * user turn + streaming placeholder + PipelineRun and dispatches the job the
 * SAME way. It also provisions a fresh app + builder conversation for callers
 * that have no app of their own (the general chat), since the pipeline is
 * app-and-conversation native.
 */
class ExpressLauncher
{
    public function __construct(
        private readonly AppManifestService $manifestService,
        private readonly BuilderCancellation $cancellation,
    ) {}

    /**
     * Persist the user turn + a streaming assistant placeholder, open the
     * PipelineRun and queue the build. Returns the run and the placeholder the
     * pipeline narrates into so the caller can shape its own response.
     *
     * When $chatMessage is given (the general-chat autoroute), the run is
     * linked back to it so ExpressDashboardJob can flip that chat message to
     * "…listo" on completion. A Builder-launched run passes null and behaves
     * exactly as before.
     *
     * @return array{run: PipelineRun, placeholder: BuilderMessage}
     */
    public function launch(App $app, BuilderConversation $conversation, string $prompt, ?string $model = null, ?ChatMessage $chatMessage = null): array
    {
        ['run' => $run, 'placeholder' => $placeholder] = $this->openRun($app, $conversation, $prompt, 'dashboard_express', $chatMessage);

        ExpressDashboardJob::dispatch($placeholder->id, $run->id, $prompt, $model);

        return ['run' => $run, 'placeholder' => $placeholder];
    }

    /**
     * The app-build sibling of launch(): opens the same user-turn + streaming
     * placeholder + PipelineRun, but runs a full-app builder turn (the complete
     * builder tool set) via ExpressAppJob instead of the dashboard pipeline. Same
     * chat linkage, so notifyChatReady flips the launching chat message when the
     * app lands.
     *
     * @return array{run: PipelineRun, placeholder: BuilderMessage}
     */
    public function launchAppBuild(App $app, BuilderConversation $conversation, string $prompt, ?string $model = null, ?ChatMessage $chatMessage = null): array
    {
        ['run' => $run, 'placeholder' => $placeholder] = $this->openRun($app, $conversation, $prompt, 'app_express', $chatMessage);

        ExpressAppJob::dispatch($placeholder->id, $run->id, $prompt, $model);

        return ['run' => $run, 'placeholder' => $placeholder];
    }

    /**
     * Persist the user turn + a streaming assistant placeholder and open the
     * PipelineRun the background job narrates into. Shared by launch() and
     * launchAppBuild() so both surfaces open a turn the SAME way; the only
     * difference is the run `kind` and which job the caller dispatches.
     *
     * @return array{run: PipelineRun, placeholder: BuilderMessage}
     */
    private function openRun(App $app, BuilderConversation $conversation, string $prompt, string $kind, ?ChatMessage $chatMessage): array
    {
        // A new turn re-arms the build machinery: clear any standing Detener
        // flag so this run (and its chain) can proceed.
        $this->cancellation->clear($conversation);

        BuilderMessage::create([
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

        $run = PipelineRun::create([
            'app_id' => $app->id,
            'conversation_id' => $conversation->id,
            'chat_id' => $chatMessage?->chat_id,
            'chat_message_id' => $chatMessage?->id,
            'kind' => $kind,
            'prompt' => $prompt,
        ]);

        return ['run' => $run, 'placeholder' => $placeholder];
    }

    /**
     * Flip the chat message that launched this run to its terminal state and
     * rebroadcast it, so an open chat updates in place (reusing ChatStreamComplete,
     * which the chat client already upserts on). No-op for a Builder-launched run
     * (no linked chat message) or if the message was since deleted. Called by
     * ExpressDashboardJob once the run reaches a terminal status.
     */
    public function notifyChatReady(PipelineRun $run, App $app): void
    {
        if (($run->chat_message_id ?? null) === null) {
            return;
        }

        $message = ChatMessage::query()->find($run->chat_message_id);
        if ($message === null) {
            return;
        }

        $message->forceFill([
            'content' => $this->chatCompletionContent($run, $app),
            'status' => 'complete',
        ])->save();

        try {
            ChatStreamComplete::dispatch($message->refresh());
        } catch (\Throwable) {
            // The persisted content is the truth; an open chat catches up on reload.
        }
    }

    /**
     * The terminal chat-message body: a link to the finished dashboard/app on
     * success, or an honest "couldn't finish it" pointing at the Builder so the
     * message never hangs on "te avisaré…". The noun follows the run kind so an
     * app build reads as an app, not a dashboard.
     */
    private function chatCompletionContent(PipelineRun $run, App $app): string
    {
        $isApp = $run->kind === 'app_express';

        if ($run->status === 'succeeded') {
            $url = route('apps.runtime', ['app_slug' => $app->slug]);

            return $isApp
                ? "✅ Tu app **{$app->name}** está lista. [Ábrela aquí →]({$url})"
                : "✅ Tu dashboard **{$app->name}** está listo. [Consúltalo aquí →]({$url})";
        }

        $url = route('apps.builder', $app);

        return $isApp
            ? "⚠️ No pude terminar la app **{$app->name}**. "
                ."Abre el Builder para ver qué pasó: [ir al Builder →]({$url})"
            : "⚠️ No pude terminar el dashboard **{$app->name}**. "
                ."Abre el Builder para ver qué pasó: [ir al Builder →]({$url})";
    }

    /**
     * Provision a fresh, empty app (named deterministically from the prompt)
     * plus its builder conversation, for a caller with no app of its own — the
     * general chat. The app opens private and inherits the org brand like any
     * create_app; its first build turn (dashboard Express or full-app build)
     * fills it in.
     *
     * @return array{0: App, 1: BuilderConversation}
     */
    public function provisionApp(User $user, string $prompt): array
    {
        $name = AppNaming::nameFromPrompt($prompt) ?? AppNaming::UNTITLED;

        $app = App::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'slug' => AppNaming::uniqueSlug($name, $user->organization_id),
            'name' => $name,
            'description' => AppNaming::descriptionFromPrompt($prompt),
            'visibility' => Visibility::Private,
        ]);

        $this->manifestService->createVersion(
            $app,
            $this->manifestService->initialManifest($app),
            $user,
            'Initial version',
        );

        $conversation = BuilderConversation::create([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return [$app, $conversation];
    }
}
