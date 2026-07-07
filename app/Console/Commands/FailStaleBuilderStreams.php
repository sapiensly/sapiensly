<?php

namespace App\Console\Commands;

use App\Events\Builder\BuilderStreamComplete;
use App\Models\BuilderMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves app-builder messages orphaned mid-stream. RunBuilderAiJob catches
 * its own exceptions and its failed() handler covers a clean timeout, but a
 * HARD kill of the worker (deploy restart, OOM, SIGKILL) skips both — leaving
 * the assistant placeholder stuck in `pending`/`streaming` forever, so the
 * builder UI spins with no way to recover.
 *
 * Same two-message contract as every other ending: a placeholder that already
 * narrated progress KEEPS that narration (closed as `none`) and the
 * interruption note arrives as a NEW error message; only a placeholder that
 * never said anything is flipped to the error in place. Both are broadcast as
 * completions so an open UI resolves live without a reload.
 *
 * Runs on the owner connection: builder_messages lives in the RLS-protected
 * tenant schema and this sweep is global across tenants.
 */
#[Signature('builder:fail-stale-streams')]
#[Description('Resolve app-builder messages stuck in pending/streaming past the turn cap.')]
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
            ->where('m.role', 'assistant')
            ->where('m.created_at', '<', $cutoff)
            ->where(function ($q) {
                // A turn still mid-stream (the worker never finalized it).
                $q->whereIn('m.status', ['pending', 'streaming'])
                    // OR a finalization that half-landed: the placeholder was
                    // flipped to `none` (kept-narration) but its report/error
                    // never followed — so a narrated `none` that is still the
                    // NEWEST message in its conversation is orphaned. Observed
                    // on an Express build killed between the status flip and
                    // the report insert; the pending/streaming sweep can't see
                    // it because the status already moved on.
                    ->orWhere(function ($orphan) {
                        $orphan->where('m.status', 'none')
                            ->whereRaw("trim(m.content) <> ''")
                            ->whereNotExists(function ($sub) {
                                // No later message (ULID ids sort in time order).
                                $sub->select(DB::raw('1'))
                                    ->from('tenant.builder_messages as later')
                                    ->whereColumn('later.conversation_id', 'm.conversation_id')
                                    ->whereColumn('later.id', '>', 'm.id');
                            });
                    });
            })
            ->get(['m.id', 'm.conversation_id', 'm.content', 'm.status', 'u.locale']);

        $fallbackLocale = (string) config('app.fallback_locale', 'en');
        $failed = 0;

        foreach ($stale as $row) {
            $reason = __(self::ERROR_MESSAGE, [], (string) ($row->locale ?? $fallbackLocale));
            $hasNarration = trim((string) $row->content) !== '';
            $errorMessageId = $hasNarration ? 'bmsg_'.strtolower((string) Str::ulid()) : (string) $row->id;

            if ($hasNarration) {
                // Progress stays; the interruption is its own message.
                DB::connection('pgsql')->table('tenant.builder_messages')
                    ->where('id', $row->id)
                    ->update(['status' => 'none', 'updated_at' => now()]);

                DB::connection('pgsql')->table('tenant.builder_messages')->insert([
                    'id' => $errorMessageId,
                    'conversation_id' => $row->conversation_id,
                    'role' => 'assistant',
                    'content' => $reason,
                    'status' => 'error',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Nothing narrated — the placeholder itself becomes the error.
                DB::connection('pgsql')->table('tenant.builder_messages')
                    ->where('id', $row->id)
                    ->update(['status' => 'error', 'content' => $reason, 'updated_at' => now()]);
            }

            $failed++;

            // Resolve any OPEN UI live: complete the (kept) placeholder and
            // append the error message. Completion events — never StreamError,
            // which client-side overwrites the bubble's content. Best-effort:
            // a Reverb outage must not fail the sweep.
            try {
                foreach (array_unique([(string) $row->id, $errorMessageId]) as $id) {
                    $message = BuilderMessage::on('pgsql')->find($id);
                    if ($message !== null) {
                        broadcast(new BuilderStreamComplete($message));
                    }
                }
            } catch (\Throwable) {
                // The reload path still shows the persisted state.
            }
        }

        $this->info("Resolved {$failed} stale builder message(s) (cutoff: ".self::CAP_SECONDS.'s).');

        return self::SUCCESS;
    }
}
