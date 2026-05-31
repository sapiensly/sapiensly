<?php

namespace App\Jobs;

use App\Events\Debate\DebateTurnError;
use App\Models\DebateTurn;
use App\Services\Debate\DebateOrchestrator;
use App\Services\Debate\DebateTurnStreamer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Streams one participant's argument for a round. Dispatched as part of a
 * Bus::batch so all participants of a round run in parallel on the `debate`
 * queue.
 */
class RunDebateTurnJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $turnId) {}

    public function viaQueue(): string
    {
        return 'debate';
    }

    public function handle(DebateTurnStreamer $streamer, DebateOrchestrator $orchestrator): void
    {
        $turn = DebateTurn::query()->with(['debate.user', 'round', 'participant'])->find($this->turnId);
        if ($turn === null) {
            Log::warning('RunDebateTurnJob: turn disappeared', ['turn_id' => $this->turnId]);

            return;
        }

        if ($turn->participant === null) {
            Log::warning('RunDebateTurnJob: participant turn has no participant', ['turn_id' => $this->turnId]);

            return;
        }

        $instructions = $orchestrator->debaterInstructions($turn->participant);
        $prompt = $orchestrator->buildTurnPrompt($turn);
        $model = $turn->model ?? $turn->participant->model;

        $streamer->stream($turn, $instructions, $prompt, $model);
    }

    public function failed(?Throwable $e): void
    {
        $turn = DebateTurn::query()->find($this->turnId);
        if ($turn === null || ! in_array($turn->status, ['streaming', 'pending'], true)) {
            return;
        }

        $reason = $e?->getMessage() ?? 'This turn did not finish in time.';
        $turn->status = 'error';
        $turn->error = $reason;
        $turn->save();

        try {
            broadcast(new DebateTurnError($turn->debate_id, $turn->id, $reason));
        } catch (Throwable) {
            // swallow
        }
    }
}
