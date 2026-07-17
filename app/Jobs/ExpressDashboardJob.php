<?php

namespace App\Jobs;

use App\Events\Builder\BuilderStreamChunk;
use App\Events\Builder\BuilderStreamComplete;
use App\Models\BuilderMessage;
use App\Models\PipelineRun;
use App\Services\Apps\AppNamer;
use App\Services\Builder\BuilderCancellation;
use App\Services\Express\DashboardExpressPhases;
use App\Services\Express\ExpressContext;
use App\Services\Express\ExpressLauncher;
use App\Services\Express\ExpressPipeline;
use App\Services\Manifest\AppManifestService;
use App\Support\Apps\AppNaming;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one L4 Dashboard Express pipeline, narrating its phases into the
 * builder conversation's assistant placeholder (the same streaming surface a
 * chat turn uses — progress lines render live, Detener works, the reaper
 * covers a hard kill) and closing with the honest report: what was built,
 * what was substituted, what the source couldn't answer, which gates fell
 * back to defaults.
 */
#[Queue('ai')]
class ExpressDashboardJob implements ShouldQueue
{
    use Queueable;

    /**
     * The clean, user-facing line for an interrupted build — never the raw
     * exception (which leaks a job class name or "attempted too many times").
     */
    private const INTERRUPTED_MESSAGE = 'The build was interrupted before finishing. Whatever had already been applied is saved. Try again — and if the dashboard was very large, ask for it in parts.';

    // A broad board (7-8 connected objects, each a live acquire + its
    // previous-window read, then the model gates and the render verify) lands
    // its APPLIED dashboard at ~refine and then runs verify_render last. On a
    // healthy box the whole thing is ~50s; under load it stretches, and a 300s
    // ceiling was killing the job DURING that final verify — reporting an
    // already-applied board as "interrumpido". The budget gives the full
    // pipeline room; it stays ≤ the redis retry_after (900) and matches the ai
    // supervisor timeout so the worker and the job agree on the ceiling.
    public int $timeout = 600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $placeholderMessageId,
        public string $runId,
        public string $prompt,
        public ?string $modelOverride = null,
    ) {}

    public function handle(ExpressPipeline $pipeline, BuilderCancellation $cancellation): void
    {
        $message = BuilderMessage::query()->find($this->placeholderMessageId);
        $run = PipelineRun::query()->find($this->runId);
        if ($message === null || $run === null) {
            Log::warning('ExpressDashboardJob: placeholder or run disappeared', [
                'message_id' => $this->placeholderMessageId, 'run_id' => $this->runId,
            ]);

            return;
        }

        $launcher = app(ExpressLauncher::class);
        $conversation = $message->conversation;
        $app = $conversation->app;
        $user = $conversation->user;

        if ($cancellation->requested($conversation)) {
            $this->finalize($message, 'none', __('⏹ Build stopped by the user.', [], $user->preferredLocale()));
            $run->forceFill(['status' => 'stopped', 'finished_at' => now()])->save();
            $launcher->notifyChatReady($run, $app);

            return;
        }

        $buffer = '';
        $context = new ExpressContext($app, $user, $conversation, $this->prompt, $this->modelOverride);
        $context->onProgress = function (string $line) use (&$buffer, $message, $conversation): void {
            $buffer .= ($buffer === '' ? '' : "\n").$line;
            // Persist as we go so a mid-run reload shows where the build is.
            BuilderMessage::query()->whereKey($message->id)->update(['content' => $buffer]);
            try {
                BuilderStreamChunk::dispatch($conversation->id, $message->id, ($buffer === $line ? '' : "\n").$line);
            } catch (Throwable) {
                // Live feed is best-effort; the DB content is the truth.
            }
        };

        $run = $pipeline->execute($run, $context, DashboardExpressPhases::make());

        [$status, $report] = $this->report($run, $context, $buffer);

        // The progress narration STAYS as its own message; the report arrives
        // as a NEW one — finishing (or failing) never erases what happened.
        // Both writes are ONE transaction so a hard worker kill between them
        // can't leave the placeholder flipped to `none` with no report behind
        // it (an orphan invisible to the pending/streaming reaper): either both
        // land, or neither does and the placeholder stays `streaming` for the
        // reaper to resolve.
        $reportMessage = DB::transaction(function () use ($message, $conversation, $report, $status, $context): BuilderMessage {
            $message->forceFill(['status' => 'none'])->save();

            return BuilderMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $report,
                'status' => $status,
                'applied_version_id' => $context->page['version_id'] ?? null,
                'change_summary' => $context->page !== null
                    ? $context->tr('Dashboard ":name" built with Express', ['name' => $context->page['name']])
                    : null,
            ]);
        });

        try {
            BuilderStreamComplete::dispatch($message->refresh());
            BuilderStreamComplete::dispatch($reportMessage);
        } catch (Throwable) {
            // UI catches up from the DB.
        }

        // If this run was autorouted from the general chat, flip that chat's
        // "…te avisaré cuando esté listo" message to its terminal state now.
        $launcher->notifyChatReady($run, $app);

        // The app's DESCRIPTION describes the FINISHED dashboard, never the raw
        // prompt. Prefer the voice gate's purpose (a one-line "audience +
        // questions it answers", already AI-written post-spec); if that gate
        // defaulted (slow model), write it with the short-summary model FROM the
        // built dashboard's title/sources/KPIs/charts. Only a total model failure
        // falls back to a prompt distillation. Synced onto the manifest.
        if ($run->status === 'succeeded' && $context->page !== null && trim((string) $app->description) === '') {
            $description = trim((string) ($context->semantic['voice']['purpose'] ?? ''));
            if ($description !== '') {
                // The voice purpose is authored as "audiencia + preguntas que
                // responde" — models often split that into TWO grammatical
                // sentences, and decapitating at the first period shipped
                // "Gerentes de Éxito de Cliente y Liderazgo de Producto." as a
                // whole app description (the audience, minus everything the
                // dashboard answers). Keep the full purpose on one line; the
                // 480-char clamp below bounds a runaway.
                $description = trim((string) preg_replace('/\s+/u', ' ', $description));
            } else {
                $description = (string) (app(AppNamer::class)->describeDashboard($this->dashboardSummary($context), $user)
                    ?? AppNaming::descriptionFromPrompt($this->prompt));
                // These sources CAN return a paragraph — force one sentence.
                $description = AppNaming::firstSentence($description);
            }
            if ($description !== '') {
                $app->forceFill(['description' => Str::limit($description, 480)])->save();
                app(AppManifestService::class)->syncManifestIdentity($app->refresh());
            }
        }

        // Persist the rendered-number summary so the adversarial verifier
        // judges NUMBERS, not just labels.
        if ($context->renderedSummary !== []) {
            $result = $run->result ?? [];
            $result['rendered'] = array_slice($context->renderedSummary, 0, 40);
            $run->forceFill(['result' => $result])->save();
        }

        // G-3: adversarial verification runs AFTER v1 is on screen — it only
        // raises the ceiling, so its latency (and its model) never gate the
        // user's first look. Carry the user's chosen model so the verifier
        // falls back to it (not the builder default) when no plumbing model
        // is configured.
        if ($run->status === 'succeeded' && ! $context->economyMode) {
            VerifyExpressDashboardJob::dispatch($run->id, $this->modelOverride);
        }
    }

    /**
     * A compact description of what the built dashboard SHOWS — title, live
     * sources, KPIs and charts — fed to the short-summary model to write the
     * app description.
     */
    private function dashboardSummary(ExpressContext $context): string
    {
        $spec = $context->spec ?? [];
        $title = trim((string) ($context->semantic['voice']['title'] ?? $context->page['name'] ?? $spec['title'] ?? ''));
        $labels = fn (string $key): string => collect($spec[$key] ?? [])
            ->pluck('label')->filter()->take(8)->implode(', ');

        $parts = array_filter([
            $title !== '' ? "Título: {$title}" : '',
            ($sources = collect($context->objects)->pluck('name')->filter()->take(6)->implode(', ')) !== '' ? "Fuentes: {$sources}" : '',
            ($kpis = $labels('kpis')) !== '' ? "KPIs: {$kpis}" : '',
            ($charts = $labels('charts')) !== '' ? "Gráficas: {$charts}" : '',
        ]);

        return implode('. ', $parts);
    }

    /**
     * Compose the final message from the run outcome: the honest 1d-fit
     * narrative on success, the halt text verbatim, or a readable failure.
     *
     * @return array{0: string, 1: string}
     */
    private function report(PipelineRun $run, ExpressContext $context, string $progressLog): array
    {
        if ($run->status === 'succeeded' && $context->page !== null) {
            $lines = [
                "## ✅ {$context->page['name']}",
                '',
                $context->tr('Your dashboard is ready at **`:path`** (version :version).', [
                    'path' => $context->page['path'],
                    'version' => $context->page['version'],
                ]),
            ];
            if ($context->objects !== []) {
                $sources = collect($context->objects)->pluck('name')->filter()->values();
                $lines[] = '';
                $lines[] = $context->tr('**Live data:** :sources.', [
                    'sources' => $sources->join(', ', ' '.$context->tr('and').' '),
                ]);
            }

            // The interpreter's translation is part of the contract: the user
            // corrects the INTERPRETATION, not the board.
            if ($context->interpretedPrompt !== null) {
                $lines[] = '';
                $lines[] = $context->tr('**I interpreted your request as:** ":prompt" — if that\'s not it, tell me and I\'ll adjust.', ['prompt' => $context->interpretedPrompt]);
            }

            // Honest limitations the user asked about — kept; these are about the
            // DATA, not how the build ran.
            $caveats = [];
            foreach ($context->substitutions as $sub) {
                $caveats[] = $context->tr('For **:asked** I used **:using** (:reason).', [
                    'asked' => $sub['asked'] ?? '?',
                    'using' => $sub['using'] ?? '?',
                    'reason' => $sub['reason'] ?? '',
                ]);
            }
            foreach ($context->unanswerable as $miss) {
                $caveats[] = $context->tr('**:asked** isn\'t available in this source.', ['asked' => $miss['asked'] ?? '?']);
            }
            foreach ($context->coverageNotes as $note) {
                $caveats[] = $note;
            }
            if ($caveats !== []) {
                $lines[] = '';
                $lines[] = $context->tr('A couple of notes about the data:');
                foreach ($caveats as $caveat) {
                    $lines[] = '- '.$caveat;
                }
            }

            $lines[] = '';
            $lines[] = $context->tr('Adjust anything? Tell me what to change — titles, charts, or insights — and I\'ll refine it.');

            return ['applied', implode("\n", $lines)];
        }

        if ($run->status === 'halted_unanswerable') {
            return ['none', (string) (collect($context->notes)->last() ?: $context->tr('This source can\'t answer the request.'))];
        }

        if ($run->status === 'stopped') {
            return ['none', $context->tr('⏹ Build stopped by the user. Progress already applied is preserved.')];
        }

        // A clean, user-facing failure — no raw internal notes, no run id. The
        // data-level caveats (what the source couldn't answer) are the only
        // detail worth surfacing; the rest lives in the telemetry.
        $lines = [$context->tr('I couldn\'t finish the dashboard this time.')];
        foreach ($context->unanswerable as $miss) {
            $asked = trim((string) ($miss['asked'] ?? ''));
            if ($asked !== '' && $asked !== '?') {
                $lines[] = '- '.$context->tr('**:asked** isn\'t available in this source.', ['asked' => $asked]);
            }
        }
        $lines[] = '';
        $lines[] = $context->tr('You can try again, or tell me what to build and we\'ll do it step by step.');

        return ['error', implode("\n", $lines)];
    }

    public function failed(?Throwable $e): void
    {
        $message = BuilderMessage::query()->find($this->placeholderMessageId);
        $run = PipelineRun::query()->find($this->runId);

        if ($run !== null && in_array($run->status, ['running', null], true)) {
            $run->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e?->getMessage() ?? 'killed', 0, 1500),
                'finished_at' => now(),
            ])->save();
        }

        if ($message === null) {
            return;
        }

        // Surface the interruption unless the turn already ended with an
        // explanation. "Ended" = the placeholder is applied/error, OR a later
        // message (report/error) already follows it. Crucially we ALSO cover a
        // placeholder that a partial finalization flipped to `none` with nothing
        // behind it — the exact silent freeze observed on a hard-killed acquire,
        // where the streaming/pending guard alone let the failure vanish.
        $alreadyResolved = ! in_array($message->status, ['streaming', 'pending', 'none'], true)
            || BuilderMessage::query()
                ->where('conversation_id', $message->conversation_id)
                ->where('id', '>', $message->id)
                ->exists();
        if ($alreadyResolved) {
            return;
        }

        // NEVER surface the raw exception — it leaks internals like a job class
        // name or "attempted too many times". The technical cause is already
        // saved to $run->error above for telemetry; the user gets a clean,
        // reassuring line. Bank-first means any dashboard/objects already
        // compiled were saved as versions, so "lo aplicado quedó guardado" is
        // honest even on a hard kill mid-enrichment.
        $reason = __(self::INTERRUPTED_MESSAGE, [], $message->conversation->user->preferredLocale());
        $hasNarration = trim((string) $message->content) !== '';
        if ($hasNarration) {
            // The progress narration stays; the interruption is a NEW message.
            $this->finalize($message, 'none', $message->content);
            $errorMessage = BuilderMessage::create([
                'conversation_id' => $message->conversation_id,
                'role' => 'assistant',
                'content' => $reason,
                'status' => 'error',
            ]);
            try {
                BuilderStreamComplete::dispatch($errorMessage);
            } catch (Throwable) {
                // swallow
            }
        } else {
            // Nothing narrated — the placeholder itself becomes the error.
            $this->finalize($message, 'error', $reason);
        }
    }

    private function finalize(BuilderMessage $message, string $status, string $content): void
    {
        $message->forceFill(['status' => $status, 'content' => $content])->save();
        try {
            BuilderStreamComplete::dispatch($message->refresh());
        } catch (Throwable) {
            // swallow
        }
    }
}
