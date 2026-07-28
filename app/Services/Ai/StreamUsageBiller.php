<?php

namespace App\Services\Ai;

use App\Models\BuilderMessage;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Bills the spend of a turn that died before it could bill itself.
 *
 * A builder turn records its own usage at the end. When it never gets there —
 * killed at the wall clock, stopped by the user, or reaped as stale — the tokens
 * it already spent are only recoverable from the snapshot the stream persists
 * after each round-trip (see BuilderAiService's $persistUsage).
 *
 * This lives here because THREE different places close a dead turn and only one
 * of them used to pay for it:
 *
 *   - RunBuilderAiJob::failed()      — the wall-clock timeout. Billed.
 *   - ResolveStoppedBuildJob         — the Detener backstop. Did NOT bill.
 *   - FailStaleBuilderStreams        — the scheduled reaper. Did NOT bill.
 *
 * So a turn whose worker was hard-killed (where failed() never runs at all) got
 * closed by one of the other two and its spend vanished — get_build_cost then
 * reported a build's cost with a turn silently missing from it, which is exactly
 * what `reconciliation.unattributed_turns` was counting.
 *
 * Idempotent via the snapshot's `recorded` flag: the success path sets it true,
 * and this flips it true after billing, so a turn is never counted twice no
 * matter how many closers race for it.
 */
class StreamUsageBiller
{
    public function __construct(private readonly AiUsageRecorder $recorder) {}

    /**
     * Bill this message's pending usage snapshot, if it has one.
     *
     * @return bool True when an event was recorded.
     */
    public function bill(BuilderMessage $message): bool
    {
        $snapshot = $message->usage;

        if (! is_array($snapshot) || ($snapshot['recorded'] ?? false) === true) {
            return false;
        }

        $model = $snapshot['model'] ?? null;
        if (! is_string($model) || $model === '') {
            // A turn killed before its first round-trip completed has no model
            // and no reported usage — there is genuinely nothing to bill. Say so
            // once, so a recurring gap is visible in the logs rather than only
            // inferable from a cost reconciliation weeks later.
            Log::info('StreamUsageBiller: nothing to bill for a dead turn', [
                'message_id' => $message->id,
                'has_snapshot' => is_array($snapshot),
            ]);

            return false;
        }

        try {
            $conversation = $message->conversation()->first();

            $this->recorder->record(
                'builder',
                $model,
                $conversation?->user,
                $conversation?->organization_id,
                new Usage(
                    promptTokens: (int) ($snapshot['prompt_tokens'] ?? 0),
                    completionTokens: (int) ($snapshot['completion_tokens'] ?? 0),
                    cacheWriteInputTokens: (int) ($snapshot['cache_write_input_tokens'] ?? 0),
                    cacheReadInputTokens: (int) ($snapshot['cache_read_input_tokens'] ?? 0),
                    reasoningTokens: (int) ($snapshot['reasoning_tokens'] ?? 0),
                ),
                appId: $conversation?->app_id,
                conversationId: $message->conversation_id,
            );

            $message->usage = ['recorded' => true] + $snapshot;
            $message->save();

            return true;
        } catch (\Throwable $e) {
            Log::warning('StreamUsageBiller: billing a dead turn failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Bill by id — for closers that work off raw rows rather than models.
     */
    public function billById(?string $messageId): bool
    {
        if ($messageId === null || $messageId === '') {
            return false;
        }

        $message = BuilderMessage::query()->find($messageId);

        return $message !== null && $this->bill($message);
    }
}
