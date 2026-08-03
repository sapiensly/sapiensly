<?php

namespace App\Console\Commands;

use App\Models\Record;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Empties the trash of what has been in it long enough.
 *
 * A trash that keeps everything for ever is not a safety net, it is a storage
 * leak with a friendly name: the rows stay indexed, stay counted in the table's
 * size, and stay in every backup, to protect against a mistake nobody has
 * noticed in a month and now never will.
 *
 * Thirty days is the window, and it is deliberately NOT configurable. The
 * per-app knob that exists — activity retention — is about a log somebody reads;
 * this is about the tenant's own live data, and an app whose owner set it to
 * "one month" would be silently agreeing to lose records deleted five weeks ago
 * by an intern. One window, long enough to notice, short enough to matter.
 *
 * Deletes in batches: an unbounded DELETE across a busy tenant's records is a
 * lock every other write queues behind.
 */
class PruneTrashedRecords extends Command
{
    protected $signature = 'records:prune-trash {--days= : Override the window (for tests)} {--dry-run : Report what would go, delete nothing}';

    protected $description = 'Permanently delete records that have been in the trash past the recovery window.';

    /** How long a deleted record can still be brought back. */
    public const WINDOW_DAYS = 30;

    /** Rows per statement. */
    private const BATCH = 1000;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? self::WINDOW_DAYS);
        $cutoff = Carbon::now()->subDays($days);

        $connection = DB::connection((new Record)->getConnectionName());
        $table = (new Record)->getTable();

        // Straight to the table, past the model: the global scope hides exactly
        // the rows this command exists to remove, and `withTrashed()` on a
        // chunked delete is a footgun waiting for the day somebody edits it.
        $due = $connection->table($table)
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $cutoff)
            ->count();

        if ($this->option('dry-run')) {
            $this->line(sprintf(
                'Would permanently delete %s record(s) trashed before %s (%d days).',
                number_format($due),
                $cutoff->format('Y-m-d'),
                $days,
            ));

            return self::SUCCESS;
        }

        $total = 0;

        do {
            $deleted = $connection->table($table)
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<', $cutoff)
                ->limit(self::BATCH)
                ->delete();

            $total += $deleted;
        } while ($deleted === self::BATCH);

        $this->info(sprintf(
            'Permanently deleted %s record(s) trashed before %s.',
            number_format($total),
            $cutoff->format('Y-m-d'),
        ));

        return self::SUCCESS;
    }
}
