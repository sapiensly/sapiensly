<?php

namespace App\Jobs;

use App\Events\Builder\BuilderStreamComplete;
use App\Events\Builder\BuilderStreamError;
use App\Models\BuilderMessage;
use App\Services\Builder\BuilderAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a Builder AI turn in the background so the HTTP request can return
 * immediately. BuilderAiService::streamMessage emits BuilderStreamChunk /
 * Complete / Error broadcasts that the frontend consumes via Reverb.
 *
 * Queue selection matters here: `ai` runs on supervisor-ai which is configured
 * with timeout=300. The default queue would re-enqueue the job at retry_after
 * (~90s) — well below our $timeout — and crash it with MaxAttemptsExceeded
 * before Claude finishes its multi-tool turn.
 */
class RunBuilderAiJob implements ShouldQueue
{
    use Queueable;

    /** Max wall-clock per run. Claude with 10 tools rarely needs > 2 min. */
    public int $timeout = 300;

    /** Auto-retry is off — failures broadcast an error and the user retries. */
    public int $tries = 1;

    public function __construct(
        public string $placeholderMessageId,
        public string $userText,
        /**
         * Optional image attachment — relative path on the disk identified by
         * $attachmentDisk. Set for both flows that send Claude an image:
         * "Pedir revisión visual" (screenshot) and a chat message with a
         * paperclip/paste/drop attachment. streamMessage loads it as a
         * Laravel\Ai\StoredImage so it works against any Storage driver
         * (S3, S3-compatible, or test fakes).
         */
        public ?string $attachmentPath = null,
        public ?string $attachmentDisk = null,
        /**
         * Optional per-turn model id override. Visual review passes
         * BuilderAiService::VISUAL_REVIEW_MODEL (Sonnet 4.5) so the
         * stricter "do not add new features" rules are followed; the
         * default chat path leaves this null and falls back to Haiku.
         */
        public ?string $modelOverride = null,
    ) {}

    /**
     * Route to the `ai` supervisor (timeout=300). The default supervisor has
     * timeout=60 + retry_after=90s — both too short for a Claude tool-use
     * turn, which is what produced the "MaxAttemptsExceeded" timeouts.
     * Setting $queue directly clashes with the Queueable trait's typed
     * property; viaQueue() is the supported override hook.
     */
    public function viaQueue(): string
    {
        return 'ai';
    }

    public function handle(BuilderAiService $service): void
    {
        $message = BuilderMessage::query()->find($this->placeholderMessageId);
        if ($message === null) {
            Log::warning('RunBuilderAiJob: placeholder message disappeared', [
                'message_id' => $this->placeholderMessageId,
            ]);

            return;
        }

        $service->streamMessage(
            $message,
            $this->userText,
            $this->attachmentPath,
            $this->attachmentDisk,
            $this->modelOverride,
        );
    }

    /**
     * Called by the queue worker when the job exhausts retries (including
     * timeout-induced re-enqueues). The streamMessage path catches its own
     * exceptions, but THIS path covers the cases where the runner itself died
     * — the placeholder is then frozen in `streaming` forever unless we
     * mark it failed here.
     */
    public function failed(?Throwable $e): void
    {
        $message = BuilderMessage::query()->find($this->placeholderMessageId);
        if ($message === null) {
            return;
        }
        // Only touch placeholders that the worker never got to update —
        // a completed turn (status=applied/none/error) should be left alone.
        if (! in_array($message->status, ['streaming', 'pending'], true)) {
            return;
        }

        // The turn died mid-loop (typically the 300s timeout) before the
        // end-of-turn apply ran. If propose_change checkpointed valid
        // accumulated work onto the message, bank it as a new version so the
        // progress survives and the next turn resumes from the real manifest
        // instead of restarting from an empty app.
        $checkpointError = null;
        if (! empty($message->proposed_patch)) {
            try {
                $version = app(BuilderAiService::class)->applyCheckpoint($message);
                if ($version !== null) {
                    $note = "I ran out of time, but I saved the progress so far ({$message->change_summary}). Send \"continúa\" to keep going.";
                    $message->content = $message->content ?: $note;
                    $message->save();
                    try {
                        BuilderStreamComplete::dispatch($message->refresh());
                    } catch (Throwable) {
                        // swallow — UI catches up via DB status on next load
                    }

                    return;
                }
            } catch (Throwable $applyError) {
                // The turn checkpointed valid work, but banking it failed —
                // this is exactly where a permission/role error reaching the
                // platform schema surfaces. Carry the real reason into the
                // message so the user (and us, without the worker logs) can see
                // WHY the progress was lost, instead of a generic timeout note.
                $checkpointError = mb_substr($applyError->getMessage(), 0, 1500);
                Log::error('RunBuilderAiJob: checkpoint recovery failed — progress NOT saved', [
                    'message_id' => $message->id,
                    'error' => $applyError->getMessage(),
                ]);
            }
        }

        $reason = $checkpointError !== null
            ? 'The turn was interrupted and the saved progress could not be applied: '.$checkpointError
            : ($e?->getMessage() ?? 'The Builder AI job did not finish in time.');
        $message->status = 'error';
        $message->content = $reason;
        $message->save();

        Log::error('RunBuilderAiJob failed; placeholder marked error', [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'error' => $reason,
        ]);

        // Best-effort: tell the frontend so the spinner stops. We don't
        // depend on broadcasting succeeding — if Reverb is down too, the UI
        // will catch up on the next page load via the DB status.
        try {
            broadcast(new BuilderStreamError($message->conversation_id, $message->id, $reason));
        } catch (Throwable) {
            // swallow
        }
    }
}
