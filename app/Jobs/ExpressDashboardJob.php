<?php

namespace App\Jobs;

use App\Events\Builder\BuilderStreamChunk;
use App\Events\Builder\BuilderStreamComplete;
use App\Events\Builder\BuilderStreamError;
use App\Models\BuilderMessage;
use App\Models\PipelineRun;
use App\Services\Builder\BuilderCancellation;
use App\Services\Express\DashboardExpressPhases;
use App\Services\Express\ExpressContext;
use App\Services\Express\ExpressPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one L4 Dashboard Express pipeline, narrating its phases into the
 * builder conversation's assistant placeholder (the same streaming surface a
 * chat turn uses — progress lines render live, Detener works, the reaper
 * covers a hard kill) and closing with the honest report: what was built,
 * what was substituted, what the source couldn't answer, which gates fell
 * back to defaults.
 */
class ExpressDashboardJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $placeholderMessageId,
        public string $runId,
        public string $prompt,
        public ?string $modelOverride = null,
    ) {}

    public function viaQueue(): string
    {
        return 'ai';
    }

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

        $message->forceFill([
            'content' => $report,
            'status' => $status,
            'applied_version_id' => $context->page['version_id'] ?? null,
            'change_summary' => $context->page !== null
                ? "Dashboard «{$context->page['name']}» construido con Express"
                : null,
        ])->save();

        try {
            BuilderStreamComplete::dispatch($message->refresh());
        } catch (Throwable) {
            // UI catches up from the DB.
        }
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
                "## ⚡ Dashboard listo: {$context->page['name']}",
                "**Página**: `{$context->page['path']}` (v{$context->page['version']})",
            ];
            if ($context->objects !== []) {
                $lines[] = '**Objetos conectados**: '.collect($context->objects)->pluck('name')->implode(', ');
            }
            foreach ($context->substitutions as $sub) {
                $lines[] = '- ⚠️ Pediste **'.($sub['asked'] ?? '?').'** — no existe en la fuente; usé **'.($sub['using'] ?? '?').'** ('.($sub['reason'] ?? '').').';
            }
            foreach ($context->unanswerable as $miss) {
                $lines[] = '- ❌ **'.($miss['asked'] ?? '?').'** no se puede responder con esta fuente ('.($miss['reason'] ?? '').').';
            }
            $fallbacks = collect($run->gates ?? [])
                ->filter(fn (array $g): bool => ($g['fallback_used'] ?? false) === true)
                ->keys();
            if ($fallbacks->isNotEmpty()) {
                $lines[] = '_Compuertas resueltas con su default (el modelo no respondió a tiempo): '.$fallbacks->implode(', ').'._';
            }
            $lines[] = '';
            $lines[] = 'Dime qué ajusto — títulos, gráficas, insights — y lo refino.';

            return ['applied', implode("\n", $lines)];
        }

        if ($run->status === 'halted_unanswerable') {
            return ['none', (string) (collect($context->notes)->last() ?: 'Esta fuente no puede responder el pedido.')];
        }

        if ($run->status === 'stopped') {
            return ['none', trim($progressLog."\n\n⏹ Build detenido por el usuario.")];
        }

        return ['error', 'El build Express falló: '.($run->error ?? 'error desconocido').'. El detalle quedó en el registro del pipeline ('.$run->id.').'];
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

        if ($message !== null && in_array($message->status, ['streaming', 'pending'], true)) {
            $reason = 'El build Express se interrumpió: '.($e?->getMessage() ?? 'el proceso se detuvo').'.';
            $this->finalize($message, 'error', $reason);
            try {
                broadcast(new BuilderStreamError($message->conversation_id, $message->id, $reason));
            } catch (Throwable) {
                // swallow
            }
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
