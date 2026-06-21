<?php

namespace App\Jobs;

use App\Events\Chat\ChatStreamError;
use App\Models\AppVersion;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
     * The framework's own "has been attempted too many times" / "has timed out"
     * (and a runner killed with no exception) all mean the same thing to a user:
     * the turn ran out of time. Other exceptions keep their message.
     */
    private function friendlyReason(?Throwable $e, ChatMessage $message): string
    {
        $ranOutOfTime = $e === null
            || $e instanceof MaxAttemptsExceededException
            || $e instanceof TimeoutExceededException;

        if (! $ranOutOfTime) {
            return $e->getMessage() !== '' ? $e->getMessage() : __('The chat request did not finish in time.');
        }

        $locale = $this->ownerLocale($message);
        $reason = __('The assistant ran out of time finishing this response. Please try again — and if it was a large build, ask for it in smaller steps.', [], $locale);

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
