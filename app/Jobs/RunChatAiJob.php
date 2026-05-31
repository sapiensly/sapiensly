<?php

namespace App\Jobs;

use App\Events\Chat\ChatStreamError;
use App\Models\ChatMessage;
use App\Services\Chat\ChatAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a Chat AI turn in the background so the HTTP request returns
 * immediately. ChatAiService::streamMessage emits ChatStreamChunk / Complete
 * / Error broadcasts the frontend consumes via Reverb.
 *
 * Routes to the `ai` queue (Horizon supervisor-ai, timeout=300) — the default
 * queue's short retry_after would re-enqueue and crash long turns.
 */
class RunChatAiJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $toolIds
     */
    public function __construct(
        public string $placeholderMessageId,
        public string $userText,
        public ?string $modelOverride = null,
        public bool $webSearch = false,
        public array $toolIds = [],
    ) {}

    public function viaQueue(): string
    {
        return 'ai';
    }

    public function handle(ChatAiService $service): void
    {
        $message = ChatMessage::query()->find($this->placeholderMessageId);
        if ($message === null) {
            Log::warning('RunChatAiJob: placeholder message disappeared', [
                'message_id' => $this->placeholderMessageId,
            ]);

            return;
        }

        $service->streamMessage($message, $this->userText, $this->modelOverride, $this->webSearch, $this->toolIds);
    }

    /**
     * Covers the case where the runner itself died (timeout) — otherwise the
     * placeholder is frozen in `streaming` forever.
     */
    public function failed(?Throwable $e): void
    {
        $message = ChatMessage::query()->find($this->placeholderMessageId);
        if ($message === null) {
            return;
        }
        if (! in_array($message->status, ['streaming', 'pending'], true)) {
            return;
        }

        $reason = $e?->getMessage() ?? 'The chat request did not finish in time.';
        $message->status = 'error';
        $message->error = $reason;
        $message->save();

        Log::error('RunChatAiJob failed; placeholder marked error', [
            'message_id' => $message->id,
            'chat_id' => $message->chat_id,
            'error' => $reason,
        ]);

        try {
            broadcast(new ChatStreamError($message->chat_id, $message->id, $reason));
        } catch (Throwable) {
            // swallow
        }
    }
}
