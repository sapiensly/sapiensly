<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\IntegrationExecution;
use App\Support\Tenancy\TenantScopes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Trims the IntegrationExecution history on a per-integration basis. Keeps
 * the last {count} rows for every integration AND anything created within
 * {days}, deleting everything older. Configure via config/integrations.php
 * (`execution_retention.count` and `execution_retention.days`).
 *
 * Runs once per TENANT. `integration_executions` is a tenant table under RLS,
 * and a scheduled command has no request and therefore no scope — so the
 * connection runs as `tenant_app` with no GUC set, every query returns zero
 * rows, and this reported "Pruned 0" forever while looking healthy. The
 * integrations it iterates live in the platform schema, which is why THAT half
 * always worked and hid the other.
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

        TenantScopes::each(Integration::query()->withTrashed(), function (?string $organizationId, ?int $userId) use ($keepCount, $cutoff, &$totalDeleted) {
            $integrations = Integration::query()
                ->withTrashed()
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->select('id')
                ->get();

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
