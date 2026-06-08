<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Services\Chat\ChatAiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Folds the older messages of a long chat into its rolling summary (using the
 * `summary_large` AI default) so future turns send a condensed memory plus a
 * short verbatim tail instead of the whole transcript. Dispatched after a turn
 * completes; the tenant scope is propagated by the global queue payload hook.
 *
 * Routes to the `ai` queue and is unique per chat so overlapping turns don't
 * summarize the same range twice.
 */
class SummarizeChatHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(public string $chatId) {}

    public function uniqueId(): string
    {
        return $this->chatId;
    }

    public function viaQueue(): string
    {
        return 'ai';
    }

    public function handle(ChatAiService $service): void
    {
        $chat = Chat::query()->find($this->chatId);
        if ($chat === null) {
            return;
        }

        $service->summarizeHistory($chat);
    }
}
