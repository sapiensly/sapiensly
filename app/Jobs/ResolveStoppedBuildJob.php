<?php

namespace App\Jobs;

use App\Events\Builder\BuilderStreamComplete;
use App\Models\BuilderMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Backstop for "Detener" when the turn is already DEAD. Cancellation is a
 * cooperative flag a LIVE turn polls and finalizes on (banking its accumulated
 * progress) — but a turn whose worker already died has nothing to poll it, so
 * the user clicks Detener and the placeholder spins on in `streaming` until the
 * global stale-stream reaper eventually (10 min) resolves it.
 *
 * stopBuild dispatches this with a short grace delay. If a live turn finalized
 * within that window, the placeholder has left streaming/pending and this
 * no-ops. If it is still streaming — the turn was dead — this closes it with
 * the user-stop message, so Detener resolves in seconds, not minutes. The flip
 * is a single conditional UPDATE, so it can never race a live turn into a
 * double finalization.
 */
class ResolveStoppedBuildJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $conversationId,
        public string $placeholderId,
    ) {}

    public function viaQueue(): string
    {
        return 'ai';
    }

    public function handle(): void
    {
        $placeholder = BuilderMessage::query()->find($this->placeholderId);
        if ($placeholder === null || ! in_array($placeholder->status, ['streaming', 'pending'], true)) {
            return; // a live turn finalized it within the grace window — nothing to do
        }

        $stop = '⏹ Build detenido por el usuario. El progreso ya aplicado se conserva.';
        $hasNarration = trim((string) $placeholder->content) !== '';

        // Atomic: only the winner of this flip finalizes (a live turn that
        // finalizes at the same instant leaves status != streaming/pending and
        // this affects 0 rows).
        $flipped = BuilderMessage::query()
            ->whereKey($placeholder->id)
            ->whereIn('status', ['streaming', 'pending'])
            ->update($hasNarration
                ? ['status' => 'none', 'updated_at' => now()]
                : ['status' => 'none', 'content' => $stop, 'updated_at' => now()]);

        if ($flipped === 0) {
            return; // lost the race — a live turn just finalized
        }

        $stopMessageId = $placeholder->id;
        if ($hasNarration) {
            // Keep the narration; the stop confirmation is its own message.
            $stopMessageId = 'bmsg_'.strtolower((string) Str::ulid());
            BuilderMessage::query()->create([
                'id' => $stopMessageId,
                'conversation_id' => $this->conversationId,
                'role' => 'assistant',
                'content' => $stop,
                'status' => 'none',
            ]);
        }

        // Resolve any open UI live; best-effort.
        try {
            foreach (array_unique([$placeholder->id, $stopMessageId]) as $id) {
                $message = BuilderMessage::query()->find($id);
                if ($message !== null) {
                    broadcast(new BuilderStreamComplete($message));
                }
            }
        } catch (Throwable) {
            // The reload path shows the persisted state.
        }
    }
}
