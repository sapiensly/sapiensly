<?php

namespace App\Jobs;

use App\Models\Debate;
use App\Services\Debate\DebateOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Kicks off a debate off the HTTP request so creation returns immediately.
 * Routes to the dedicated `debate` queue (Horizon supervisor-debate).
 */
class StartDebateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public string $debateId) {}

    public function viaQueue(): string
    {
        return 'debate';
    }

    public function handle(DebateOrchestrator $orchestrator): void
    {
        $debate = Debate::query()->with('participants')->find($this->debateId);
        if ($debate === null) {
            Log::warning('StartDebateJob: debate disappeared', ['debate_id' => $this->debateId]);

            return;
        }

        $orchestrator->start($debate);
    }
}
