<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Services\Ai\AiUsageReport;
use App\Services\Platform\PlatformInventory;
use App\Services\Platform\PlatformProbe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('The state of the WHOLE platform in one call: how many organizations/users/apps/agents exist, how many accounts are blocked or unverified, tenant row volumes, AI spend for the period with the top-spending organizations, growth over the window, and a health roll-up (Horizon, Reverb, Redis, Postgres, queue depth, failed jobs). Counts span every organization, not the one this connection is bound to. Read-only. Start here, then drill in with inspect_organization, get_platform_spend or platform_health.')]
class PlatformOverviewTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'in:7,30,90'],
        ]);

        $days = (int) ($validated['days'] ?? 30);

        $inventory = app(PlatformInventory::class);
        $probe = app(PlatformProbe::class);
        $spend = app(AiUsageReport::class)->platformWide($days);
        $queues = $probe->queueDepths();

        return Response::json([
            'range_days' => $days,
            'counts' => $inventory->counts(),
            'growth' => $inventory->growth($days),
            'spend' => [
                'totals' => $spend['totals'] ?? null,
                'by_source' => $spend['by_source'] ?? null,
                'top_organizations' => array_slice($spend['by_org'] ?? [], 0, 10),
            ],
            'health' => [
                'horizon_running' => $probe->horizonRunning(),
                'reverb_reachable' => $probe->reverbReachable(),
                'redis_reachable' => $probe->redis()['reachable'],
                'database_driver' => $probe->database()['driver'],
                'queue_pending' => $queues['pending_total'],
                'queue_failed' => $queues['failed'],
            ],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Window for spend and growth: 7, 30 or 90. Default 30.'),
        ];
    }
}
