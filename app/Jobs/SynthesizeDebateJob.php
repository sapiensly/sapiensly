<?php

namespace App\Jobs;

use App\Events\Debate\DebateComplete;
use App\Events\Debate\DebateStatusChanged;
use App\Events\Debate\DebateTurnError;
use App\Models\DebateTurn;
use App\Services\Debate\DebateOrchestrator;
use App\Services\Debate\DebateTurnStreamer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Streams the moderator's final synthesis, sets each participant's final stance
 * from the latest moderator assessment, marks the debate completed and reveals
 * the Conclusions panel.
 */
#[Queue('debate')]
class SynthesizeDebateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $turnId) {}

    public function handle(DebateTurnStreamer $streamer, DebateOrchestrator $orchestrator): void
    {
        $turn = DebateTurn::query()->with(['debate.user', 'round'])->find($this->turnId);
        if ($turn === null) {
            Log::warning('SynthesizeDebateJob: turn disappeared', ['turn_id' => $this->turnId]);

            return;
        }

        $debate = $turn->debate;

        $streamer->stream(
            $turn,
            $orchestrator->synthesisInstructions(),
            $orchestrator->buildSynthesisPrompt($debate),
            $turn->model ?? $debate->moderator_model,
        );

        $turn->round->update(['status' => 'complete']);

        // Set each participant's final stance from the last moderator assessment.
        $stances = $orchestrator->latestStances($debate);
        foreach ($debate->participants as $participant) {
            $key = (string) ($participant->position + 1);
            $stance = $stances[$key] ?? null;
            if (in_array($stance, ['agree', 'partial', 'dissent'], true)) {
                $participant->update(['final_stance' => $stance]);
            }
        }

        $debate->forceFill(['status' => 'completed', 'last_activity_at' => now()])->save();

        try {
            broadcast(new DebateStatusChanged($debate));
            broadcast(new DebateComplete($debate->refresh()));
        } catch (Throwable $e) {
            Log::warning('SynthesizeDebateJob: completion broadcast failed', ['error' => $e->getMessage()]);
        }
    }

    public function failed(?Throwable $e): void
    {
        $turn = DebateTurn::query()->with('debate')->find($this->turnId);
        if ($turn === null) {
            return;
        }

        if (in_array($turn->status, ['streaming', 'pending'], true)) {
            $turn->status = 'error';
            $turn->error = $e?->getMessage() ?? 'The synthesis did not finish in time.';
            $turn->save();
        }

        $turn->debate?->forceFill(['status' => 'failed'])->save();

        try {
            broadcast(new DebateTurnError($turn->debate_id, $turn->id, $turn->error ?? 'Synthesis failed.'));
        } catch (Throwable) {
            // swallow
        }
    }
}
