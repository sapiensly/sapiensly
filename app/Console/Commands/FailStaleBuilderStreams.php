<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marks app-builder messages orphaned mid-stream as errors. RunBuilderAiJob
 * catches its own exceptions and its failed() handler covers a clean timeout,
 * but a HARD kill of the worker (deploy restart, OOM, SIGKILL) skips both —
 * leaving the assistant placeholder stuck in `pending`/`streaming` forever, so
 * the builder UI spins with no way to recover. This is the builder-side twin of
 * FailStaleChatStreams: anything streaming past the job's wall-clock cap plus a
 * finalization margin cannot still be running, so it is flipped to `error`
 * (with the interrupted-turn note in the owner's language) and the user can
 * retry.
 *
 * Runs on the owner connection: builder_messages lives in the RLS-protected
 * tenant schema and this sweep is global across tenants.
 */
#[Signature('builder:fail-stale-streams')]
#[Description('Mark app-builder messages stuck in pending/streaming past the turn cap as errors.')]
class FailStaleBuilderStreams extends Command
{
    /**
     * RunBuilderAiJob's max wall-clock (its $timeout) plus grace for the
     * end-of-turn finalization (apply/checkpoint, broadcasts) before a turn
     * counts as dead. A turn cannot legitimately stream longer than this.
     */
    private const CAP_SECONDS = 300 + 300;

    private const ERROR_MESSAGE = 'The build was interrupted before finishing (the process was stopped — usually a restart or a memory limit). Please try again — and if it was a large build, ask for it in smaller steps.';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(self::CAP_SECONDS);

        $stale = DB::connection('pgsql')
            ->table('tenant.builder_messages as m')
            ->leftJoin('tenant.builder_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->leftJoin('platform.users as u', 'u.id', '=', 'c.user_id')
            ->whereIn('m.status', ['pending', 'streaming'])
            ->where('m.created_at', '<', $cutoff)
            ->get(['m.id', 'u.locale']);

        $fallbackLocale = (string) config('app.fallback_locale', 'en');
        $failed = 0;
        foreach ($stale->groupBy(fn (object $row) => $row->locale ?? $fallbackLocale) as $locale => $rows) {
            $failed += DB::connection('pgsql')
                ->table('tenant.builder_messages')
                ->whereIn('id', $rows->pluck('id')->all())
                ->update([
                    'status' => 'error',
                    'content' => __(self::ERROR_MESSAGE, [], $locale),
                    'updated_at' => now(),
                ]);
        }

        $this->info("Marked {$failed} stale builder message(s) as error (cutoff: ".self::CAP_SECONDS.'s).');

        return self::SUCCESS;
    }
}
