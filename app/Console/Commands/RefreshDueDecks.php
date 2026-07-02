<?php

namespace App\Console\Commands;

use App\Jobs\RefreshDeckJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hourly sweep for Living Decks: finds presentations whose auto-refresh is
 * due and queues one RefreshDeckJob per deck (tenant scope travels on the
 * job). The sweep reads via the owner `pgsql` connection because the console
 * has no tenant GUCs — it only reads scheduling metadata; all real work runs
 * inside the job under the deck's own tenant context.
 */
class RefreshDueDecks extends Command
{
    protected $signature = 'slides:refresh-due';

    protected $description = 'Queue a data refresh for every Living Deck whose schedule is due';

    public function handle(): int
    {
        $now = Carbon::now('UTC');
        $dispatched = 0;

        $rows = DB::connection('pgsql')
            ->table('tenant.documents')
            ->where('type', 'deck')
            ->whereNull('deleted_at')
            ->whereRaw("coalesce(metadata->'refresh'->>'frequency', 'manual') not in ('manual', '')")
            ->get(['id', 'organization_id', 'user_id', 'metadata']);

        foreach ($rows as $row) {
            $metadata = json_decode((string) $row->metadata, true) ?: [];
            $refresh = (array) ($metadata['refresh'] ?? []);

            if (! $this->isDue($refresh, $now)) {
                continue;
            }

            // One dispatch per deck per hour, even if sweeps overlap.
            if (! Cache::add('slides:refresh:'.$row->id.':'.$now->format('YmdH'), true, 3600)) {
                continue;
            }

            RefreshDeckJob::dispatch(
                (string) $row->id,
                $row->organization_id !== null ? (string) $row->organization_id : null,
                (int) $row->user_id,
                'scheduled_refresh',
            );
            $dispatched++;
        }

        $this->info("Queued {$dispatched} deck refresh(es).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $refresh
     */
    private function isDue(array $refresh, Carbon $now): bool
    {
        $frequency = (string) ($refresh['frequency'] ?? 'manual');
        $hour = (int) ($refresh['hour'] ?? 7);
        $last = isset($refresh['last_refreshed_at'])
            ? Carbon::parse((string) $refresh['last_refreshed_at'])
            : null;

        return match ($frequency) {
            'hourly' => $last === null || $last->lt($now->copy()->subMinutes(55)),
            'daily' => $now->hour === $hour
                && ($last === null || $last->lt($now->copy()->subHours(20))),
            'weekly' => $now->isMonday() && $now->hour === $hour
                && ($last === null || $last->lt($now->copy()->subDays(6))),
            'monthly' => $now->day === 1 && $now->hour === $hour
                && ($last === null || $last->lt($now->copy()->subDays(27))),
            default => false,
        };
    }
}
