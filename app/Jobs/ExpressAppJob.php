<?php

namespace App\Jobs;

use App\Models\App;
use App\Models\BuilderMessage;
use App\Models\PipelineRun;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\BuilderCancellation;
use App\Services\Express\ExpressLauncher;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a full-app build as ONE real builder turn in the background, so the
 * general chat can hand off "build me an app for X" the same way it hands off a
 * dashboard. It reuses the exact builder engine the in-app builder uses —
 * BuilderAiService::streamMessage with the complete tool set (scaffold_app,
 * pages, workflows, seed) — which streams onto the builder conversation surface
 * and auto-applies the turn's proposal as a new version. When the turn lands, it
 * flips the launching chat message to "app lista" (or an honest failure) via
 * ExpressLauncher::notifyChatReady, mirroring ExpressDashboardJob.
 *
 * Single-turn by design: a chat handoff wants one autonomous build then a
 * notification, so it does NOT chain continueFromPlan/continueAutonomously —
 * further refinement happens on the Builder surface the user is linked to.
 */
#[Queue('ai')]
class ExpressAppJob implements ShouldQueue
{
    use Queueable;

    // A broad app scaffold (many objects + relations + pages, then optional
    // seeding) can stretch well past a dashboard build. The budget matches
    // ExpressDashboardJob: generous, ≤ the redis retry_after (900) and the ai
    // supervisor timeout, so the worker and the job agree on the ceiling.
    public int $timeout = 600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $placeholderMessageId,
        public string $runId,
        public string $prompt,
        public ?string $modelOverride = null,
    ) {}

    public function handle(BuilderAiService $service, BuilderCancellation $cancellation): void
    {
        $message = BuilderMessage::query()->find($this->placeholderMessageId);
        $run = PipelineRun::query()->find($this->runId);
        if ($message === null || $run === null) {
            Log::warning('ExpressAppJob: placeholder or run disappeared', [
                'message_id' => $this->placeholderMessageId, 'run_id' => $this->runId,
            ]);

            return;
        }

        $launcher = app(ExpressLauncher::class);
        $conversation = $message->conversation;
        $app = $conversation->app;

        if ($cancellation->requested($conversation)) {
            $message->forceFill(['status' => 'none', 'content' => '⏹ Build detenido por el usuario.'])->save();
            $run->forceFill(['status' => 'stopped', 'finished_at' => now()])->save();
            $launcher->notifyChatReady($run, $app);

            return;
        }

        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();

        // The real builder turn: full tool set, streams onto the builder
        // conversation, auto-applies the proposal as a version. streamMessage
        // catches its own model/apply failures and finalizes the placeholder,
        // so a build that couldn't apply returns here with no new objects.
        $service->streamMessage($message, $this->prompt, null, null, $this->modelOverride, true);

        $built = $this->appHasObjects($app);
        $run->forceFill([
            'status' => $built ? 'succeeded' : 'failed',
            'finished_at' => now(),
        ])->save();

        // Flip the launching chat message to its terminal state (no-op for a
        // Builder-launched run with no linked chat message).
        $launcher->notifyChatReady($run, $app->refresh());
    }

    /**
     * The definitive "an app got built" signal: the applied manifest carries at
     * least one object. Robust across the streamMessage status nuances — a turn
     * that only wrote a plan or errored leaves the manifest object-less.
     */
    private function appHasObjects(App $app): bool
    {
        $manifest = app(AppManifestService::class)->getActiveManifest($app->refresh());

        return is_array($manifest) && ! empty($manifest['objects'] ?? []);
    }

    /**
     * A hard job failure (timeout / worker kill) outside streamMessage's own
     * try/catch. Mark the run failed and still give the chat its terminal
     * notice, so the "…te avisaré" bubble never hangs forever.
     */
    public function failed(?Throwable $e): void
    {
        $run = PipelineRun::query()->find($this->runId);
        $message = BuilderMessage::query()->find($this->placeholderMessageId);

        if ($run !== null && in_array($run->status, ['running', null], true)) {
            $run->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e?->getMessage() ?? 'killed', 0, 1500),
                'finished_at' => now(),
            ])->save();
        }

        $app = $message?->conversation?->app;
        if ($run !== null && $app !== null) {
            app(ExpressLauncher::class)->notifyChatReady($run, $app);
        }
    }
}
