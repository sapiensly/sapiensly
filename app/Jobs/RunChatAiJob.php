<?php

namespace App\Jobs;

use App\Events\Chat\ChatStreamError;
use App\Models\AppVersion;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
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
#[Queue('ai')]
class RunChatAiJob implements ShouldQueue
{
    use Queueable;

    // Below the supervisor-ai worker timeout (300s) so the job fails cleanly
    // before the worker's hard pcntl alarm. ChatAiService's in-stream idle
    // watchdog is the primary guard and trips well before this.
    public int $timeout = 280;

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

        $reason = $this->friendlyReason($e, $message);
        $message->status = 'error';
        $message->error = $reason;
        $message->save();

        Log::error('RunChatAiJob failed; placeholder marked error', [
            'message_id' => $message->id,
            'chat_id' => $message->chat_id,
            // Log the technical cause, not the user-facing copy.
            'error' => $e?->getMessage() ?? 'no exception (runner killed)',
            'exception' => $e === null ? null : $e::class,
        ]);

        try {
            broadcast(new ChatStreamError($message->chat_id, $message->id, $reason));
        } catch (Throwable) {
            // swallow
        }
    }

    /**
     * Turn the failure into a clear, actionable message in the owner's language.
     * We distinguish two job-death modes that read differently to a user:
     *  - a genuine timeout (framework's "attempted too many times" / "has timed
     *    out") — the model took too long;
     *  - a runner killed with NO catchable exception ($e === null) — the process
     *    was stopped mid-turn (a deploy/restart or a memory limit), not a clock.
     * Other exceptions keep their own message.
     */
    private function friendlyReason(?Throwable $e, ChatMessage $message): string
    {
        $timedOut = $e instanceof MaxAttemptsExceededException
            || $e instanceof TimeoutExceededException;
        $interrupted = $e === null;

        if (! $timedOut && ! $interrupted) {
            return $e->getMessage() !== '' ? $e->getMessage() : __('The chat request did not finish in time.');
        }

        $locale = $this->ownerLocale($message);
        $reason = $timedOut
            ? __('The assistant ran out of time finishing this response. Please try again — and if it was a large build, ask for it in smaller steps.', [], $locale)
            : __('The assistant was interrupted before finishing this response (the process was stopped — usually a restart or a memory limit). Please try again — and if it was a large build, ask for it in smaller steps.', [], $locale);

        $apps = $this->appsBuiltDuringTurn($message);
        if ($apps !== []) {
            $reason .= ' '.__('Anything it already built is saved: :names — open it from your apps (every change is a reversible version).', ['names' => implode(', ', $apps)], $locale);
        }

        return $reason;
    }

    private function ownerLocale(ChatMessage $message): string
    {
        $userId = $message->chat?->user_id;
        $user = $userId !== null ? User::find($userId) : null;

        return $user?->locale ?? (string) config('app.fallback_locale', 'en');
    }

    /**
     * Names of apps that gained a version during this turn (the placeholder's
     * creation marks the turn start) — so a cut-off build isn't silently lost.
     *
     * @return list<string>
     */
    private function appsBuiltDuringTurn(ChatMessage $message): array
    {
        $userId = $message->chat?->user_id;
        if ($userId === null) {
            return [];
        }

        try {
            return AppVersion::query()
                ->where('created_by_user_id', $userId)
                ->where('created_at', '>=', $message->created_at)
                ->with('app:id,name')
                ->get()
                ->map(fn (AppVersion $version): ?string => $version->app?->name)
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
