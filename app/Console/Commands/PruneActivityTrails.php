<?php

namespace App\Console\Commands;

use App\Models\App;
use App\Models\RecordEvent;
use App\Services\Records\ActivityRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drops activity older than each app was told to keep.
 *
 * The whole cost story of an activity log is here. Writing one is cheap;
 * keeping it for ever is what makes it the most expensive table in the system
 * by year three. So: a default of one month, no entry written for a change
 * that changed nothing, and this — running nightly, deleting in batches.
 *
 * Batched because an unbounded DELETE over a year of a busy tenant's trail is
 * a lock held across the table while every other write waits. A hundred small
 * statements that each finish are worth more than one that is correct in
 * principle and an outage in practice.
 */
class PruneActivityTrails extends Command
{
    protected $signature = 'activity:prune {--app= : Only this app id} {--dry-run : Report what would go, delete nothing}';

    protected $description = 'Delete activity trail entries past their app’s retention period.';

    /** Rows per statement. */
    private const BATCH = 1000;

    public function handle(ActivityRetention $retention): int
    {
        $apps = App::query()
            ->when($this->option('app'), fn ($q, $id) => $q->where('id', $id))
            ->get(['id', 'slug', 'organization_id', 'activity_retention_months']);

        $connection = DB::connection((new RecordEvent)->getConnectionName());
        $table = (new RecordEvent)->getTable();
        $total = 0;

        foreach ($apps as $app) {
            $cutoff = $retention->cutoffFor($app);

            $due = $connection->table($table)
                ->where('app_id', $app->id)
                ->where('created_at', '<', $cutoff)
                ->count();

            if ($due === 0) {
                continue;
            }

            // Past this, deleting row by row is an incident rather than
            // maintenance. Said out loud instead of attempted: a tenant this
            // size needs partitions that get detached, and quietly grinding
            // for an hour would hide that from whoever has to decide.
            if ($due > ActivityRetention::BATCHED_DELETE_CEILING) {
                $this->warn(sprintf(
                    '%s: %s entries past retention — too many to delete in batches. This app needs partitioned storage.',
                    $app->slug,
                    number_format($due),
                ));

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '%s: would delete %s entries older than %s (%d months).',
                    $app->slug,
                    number_format($due),
                    $cutoff->format('Y-m-d'),
                    $retention->monthsFor($app),
                ));
                $total += $due;

                continue;
            }

            do {
                $deleted = $connection->table($table)
                    ->where('app_id', $app->id)
                    ->where('created_at', '<', $cutoff)
                    ->limit(self::BATCH)
                    ->delete();

                $total += $deleted;
            } while ($deleted === self::BATCH);
        }

        $this->info(sprintf(
            '%s %s activity entries across %d app(s).',
            $this->option('dry-run') ? 'Would delete' : 'Deleted',
            number_format($total),
            $apps->count(),
        ));

        return self::SUCCESS;
    }
}
