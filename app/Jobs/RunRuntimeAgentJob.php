<?php

namespace App\Jobs;

use App\Events\Runtime\RuntimeAgentStreamError;
use App\Models\RuntimeAgentMessage;
use App\Services\Runtime\RuntimeAgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a runtime agent turn (builder power #3) in the background so the HTTP
 * request returns immediately; RuntimeAgentService::streamMessage broadcasts the
 * deltas via Reverb. Routed to the `ai` supervisor (timeout=300) like
 * RunBuilderAiJob — a tool-use turn outlives the default queue's retry window.
 */
class RunRuntimeAgentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public string $placeholderMessageId,
        public string $userText,
    ) {}

    public function viaQueue(): string
    {
        return 'ai';
    }

    public function handle(RuntimeAgentService $service): void
    {
        $message = RuntimeAgentMessage::query()->find($this->placeholderMessageId);
        if ($message === null) {
            Log::warning('RunRuntimeAgentJob: placeholder message disappeared', [
                'message_id' => $this->placeholderMessageId,
            ]);

            return;
        }

        $service->streamMessage($message, $this->userText);
    }

    public function failed(?Throwable $e): void
    {
        $message = RuntimeAgentMessage::query()->find($this->placeholderMessageId);
        if ($message === null || $message->status !== 'streaming') {
            return;
        }

        $reason = $e?->getMessage() ?? 'The agent did not finish in time.';
        $message->update(['status' => 'error', 'content' => $reason]);

        try {
            broadcast(new RuntimeAgentStreamError($message->conversation_id, $message->id, $reason));
        } catch (Throwable) {
            // swallow — DB status is the source of truth on next load
        }
    }
}
