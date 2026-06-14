<?php

namespace App\Jobs\Chat;

use App\Models\Chat;
use App\Services\Chat\ThreadSynthesizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Closes a multi-agent thread with a synthesized action proposal. Runs as the
 * tail of the agent-response chain (auto-synthesis) and on demand from the
 * manual "Synthesize" endpoint. On the dedicated `agent-responses` queue.
 */
class SynthesizeThread implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $chatId) {}

    public function viaQueue(): string
    {
        return 'agent-responses';
    }

    public function handle(ThreadSynthesizer $synthesizer): void
    {
        $chat = Chat::query()->find($this->chatId);
        if ($chat === null) {
            Log::warning('SynthesizeThread: chat disappeared', ['chat_id' => $this->chatId]);

            return;
        }

        $synthesizer->synthesize($chat);
    }
}
