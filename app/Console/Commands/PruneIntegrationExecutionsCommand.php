<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\IntegrationExecution;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Trims the IntegrationExecution history on a per-integration basis. Keeps
 * the last {count} rows for every integration AND anything created within
 * {days}, deleting everything older. Configure via config/integrations.php
 * (`execution_retention.count` and `execution_retention.days`).
 */
#[Signature('integrations:prune-executions')]
#[Description('Remove old integration executions while keeping a rolling window per integration.')]
class PruneIntegrationExecutionsCommand extends Command
{
    public function handle(): int
    {
        $keepCount = (int) config('integrations.execution_retention.count', 200);
        $keepDays = (int) config('integrations.execution_retention.days', 30);
        $cutoff = now()->subDays($keepDays);

        $totalDeleted = 0;

        Integration::query()->withTrashed()->select('id')->chunk(100, function ($integrations) use ($keepCount, $cutoff, &$totalDeleted) {
            foreach ($integrations as $integration) {
                $keptIds = IntegrationExecution::query()
                    ->where('integration_id', $integration->id)
                    ->orderByDesc('created_at')
                    ->limit($keepCount)
                    ->pluck('id');

                $deleted = IntegrationExecution::query()
                    ->where('integration_id', $integration->id)
                    ->where('created_at', '<', $cutoff)
                    ->whereNotIn('id', $keptIds)
                    ->delete();

                $totalDeleted += $deleted;
            }
        });

        $this->info(sprintf(
            'Pruned %d execution(s) (keeping last %d per integration, plus newer than %d days).',
            $totalDeleted,
            $keepCount,
            $keepDays,
        ));

        return self::SUCCESS;
    }
}
