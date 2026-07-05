<?php

namespace App\Services\Express;

use App\Models\PipelineRun;
use App\Services\Builder\BuilderCancellation;
use App\Services\Express\Contracts\ExpressPhase;
use Illuminate\Support\Facades\Log;

/**
 * The L4 Express orchestrator: PHP owns the flow, models answer bounded
 * questions inside phases. Runs the given phases in order, persisting the
 * state transition BEFORE each phase (so a dead worker leaves an honest
 * "where it was"), honoring the user's Detener flag between phases, and
 * translating the three possible endings — success, deliberate halt, failure —
 * into the run's terminal status. Phase wall-clock lands in result.phases.
 */
class ExpressPipeline
{
    public function __construct(private readonly BuilderCancellation $cancellation) {}

    /**
     * @param  list<ExpressPhase>  $phases
     */
    public function execute(PipelineRun $run, ExpressContext $context, array $phases): PipelineRun
    {
        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $timings = [];

        try {
            foreach ($phases as $phase) {
                if ($this->cancellation->requested($context->conversation)) {
                    return $this->finish($run, 'stopped', $timings, error: null);
                }

                $run->forceFill(['phase' => $phase->name()])->save();
                $context->progress($phase->announce($context));

                $startedAt = microtime(true);
                $phase->run($context, $run);
                $timings[] = [
                    'phase' => $phase->name(),
                    'seconds' => round(microtime(true) - $startedAt, 2),
                ];
            }

            return $this->finish($run, 'succeeded', $timings, error: null, context: $context);
        } catch (ExpressHalt $halt) {
            $context->note($halt->userMessage);

            return $this->finish($run, $halt->status, $timings, error: null, context: $context);
        } catch (\Throwable $e) {
            Log::error('Express pipeline failed', [
                'run_id' => $run->id, 'phase' => $run->phase, 'error' => $e->getMessage(),
            ]);

            return $this->finish($run, 'failed', $timings, error: mb_substr($e->getMessage(), 0, 1500), context: $context);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $timings
     */
    private function finish(PipelineRun $run, string $status, array $timings, ?string $error, ?ExpressContext $context = null): PipelineRun
    {
        $result = $run->result ?? [];
        $result['phases'] = $timings;
        if ($context !== null) {
            $result['page'] = $context->page;
            $result['substitutions'] = $context->substitutions;
            $result['unanswerable'] = $context->unanswerable;
            $result['notes'] = $context->notes;
            $result['objects'] = array_values(array_map(
                fn (array $o): string => (string) ($o['slug'] ?? $o['id'] ?? ''),
                $context->objects,
            ));
        }

        $run->forceFill([
            'status' => $status,
            'error' => $error,
            'result' => $result,
            'finished_at' => now(),
        ])->save();

        return $run;
    }
}
