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
    private const INTERRUPTED_MESSAGE = 'El build se interrumpió antes de terminar. Lo que ya se había aplicado quedó guardado. Vuelve a intentarlo — y si el tablero era muy amplio, pídelo por partes.';

    public int $timeout = 300;

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

        $conversation = $message->conversation;
        $app = $conversation->app;
        $user = $conversation->user;

        if ($cancellation->requested($conversation)) {
            $this->finalize($message, 'none', '⏹ Build detenido por el usuario.');
            $run->forceFill(['status' => 'stopped', 'finished_at' => now()])->save();

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
                    ? "Dashboard «{$context->page['name']}» construido con Express"
                    : null,
            ]);
        });

        try {
            BuilderStreamComplete::dispatch($message->refresh());
            BuilderStreamComplete::dispatch($reportMessage);
        } catch (Throwable) {
            // UI catches up from the DB.
        }

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
                "Tu dashboard está listo en **`{$context->page['path']}`** (versión {$context->page['version']}).",
            ];
            if ($context->objects !== []) {
                $sources = collect($context->objects)->pluck('name')->filter()->values();
                $lines[] = '';
                $lines[] = '**Datos en vivo:** '.$sources->join(', ', ' y ').'.';
            }

            // The interpreter's translation is part of the contract: the user
            // corrects the INTERPRETATION, not the board.
            if ($context->interpretedPrompt !== null) {
                $lines[] = '';
                $lines[] = '**Interpreté tu pedido como:** «'.$context->interpretedPrompt.'» — si no era eso, dímelo y lo ajusto.';
            }

            // Honest limitations the user asked about — kept; these are about the
            // DATA, not how the build ran.
            $caveats = [];
            foreach ($context->substitutions as $sub) {
                $caveats[] = 'Para **'.($sub['asked'] ?? '?').'** usé **'.($sub['using'] ?? '?').'** ('.($sub['reason'] ?? '').').';
            }
            foreach ($context->unanswerable as $miss) {
                $caveats[] = '**'.($miss['asked'] ?? '?').'** no está disponible en esta fuente.';
            }
            foreach ($context->coverageNotes as $note) {
                $caveats[] = $note;
            }
            if ($caveats !== []) {
                $lines[] = '';
                $lines[] = 'Un par de notas sobre los datos:';
                foreach ($caveats as $caveat) {
                    $lines[] = '- '.$caveat;
                }
            }

            $lines[] = '';
            $lines[] = '¿Ajustamos algo? Dime qué cambiar —títulos, gráficas o insights— y lo refino.';

            return ['applied', implode("\n", $lines)];
        }

        if ($run->status === 'halted_unanswerable') {
            return ['none', (string) (collect($context->notes)->last() ?: 'Esta fuente no puede responder el pedido.')];
        }

        if ($run->status === 'stopped') {
            return ['none', '⏹ Build detenido por el usuario. El progreso ya aplicado se conserva.'];
        }

        // A clean, user-facing failure — no raw internal notes, no run id. The
        // data-level caveats (what the source couldn't answer) are the only
        // detail worth surfacing; the rest lives in the telemetry.
        $lines = ['No pude terminar el dashboard esta vez.'];
        foreach ($context->unanswerable as $miss) {
            $asked = trim((string) ($miss['asked'] ?? ''));
            if ($asked !== '' && $asked !== '?') {
                $lines[] = '- **'.$asked.'** no está disponible en esta fuente.';
            }
        }
        $lines[] = '';
        $lines[] = 'Puedes intentarlo de nuevo, o dime qué construir y lo armamos paso a paso.';

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
        $reason = self::INTERRUPTED_MESSAGE;
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
