<?php

namespace App\Console\Commands;

use App\Events\Chat\ChatStreamError;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marks chat messages orphaned mid-stream as errors. A turn whose worker dies
 * without finalizing (deploy restart, OOM, hard kill) leaves its placeholder
 * stuck in `pending`/`streaming` forever — the UI shows an eternal spinner and
 * nothing will ever resolve it. Anything older than the stream's wall-clock cap
 * (ai.max_stream_seconds) plus a finalization margin cannot still be running,
 * so it is flipped to `error` (keeping any partial content already persisted),
 * with the same interrupted-turn message RunChatAiJob::failed() uses, in the
 * chat owner's language.
 *
 * Runs on the owner connection: chat_messages lives in the RLS-protected
 * tenant schema and this sweep is global across tenants.
 */
#[Signature('chat:fail-stale-streams')]
#[Description('Mark chat messages stuck in pending/streaming past the stream cap as errors.')]
class FailStaleChatStreams extends Command
{
    /**
     * Grace on top of ai.max_stream_seconds for finalization work (persisting
     * the buffer, usage recording, broadcasts) before a turn counts as dead.
     */
    private const FINALIZATION_MARGIN_SECONDS = 300;

    private const ERROR_MESSAGE = 'The assistant was interrupted before finishing this response (the process was stopped — usually a restart or a memory limit). Please try again — and if it was a large build, ask for it in smaller steps.';

    public function handle(): int
    {
        $capSeconds = (int) config('ai.max_stream_seconds', 600) + self::FINALIZATION_MARGIN_SECONDS;
        $cutoff = now()->subSeconds($capSeconds);

        $stale = DB::connection('pgsql')
            ->table('tenant.chat_messages as m')
            ->leftJoin('tenant.chats as c', 'c.id', '=', 'm.chat_id')
            ->leftJoin('platform.users as u', 'u.id', '=', 'c.user_id')
            ->whereIn('m.status', ['pending', 'streaming'])
            ->where('m.created_at', '<', $cutoff)
            ->get(['m.id', 'm.chat_id', 'u.locale']);

        $fallbackLocale = (string) config('app.fallback_locale', 'en');
        $failed = 0;
        foreach ($stale->groupBy(fn (object $row) => $row->locale ?? $fallbackLocale) as $locale => $rows) {
            $reason = __(self::ERROR_MESSAGE, [], $locale);

            $failed += DB::connection('pgsql')
                ->table('tenant.chat_messages')
                ->whereIn('id', $rows->pluck('id')->all())
                ->update([
                    'status' => 'error',
                    'error' => $reason,
                    'updated_at' => now(),
                ]);

            // Tell any OPEN chat UI too — without the broadcast the DB flip
            // only shows after a manual reload. Best-effort: a Reverb outage
            // must not fail the sweep.
            foreach ($rows as $row) {
                try {
                    broadcast(new ChatStreamError((string) $row->chat_id, (string) $row->id, $reason));
                } catch (\Throwable) {
                    // The reload path still shows the persisted error.
                }
            }
        }

        $this->info("Marked {$failed} stale streaming message(s) as error (cutoff: {$capSeconds}s).");

        return self::SUCCESS;
    }
}
