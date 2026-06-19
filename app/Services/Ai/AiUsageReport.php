<?php

namespace App\Services\Ai;

use App\Models\AiUsageEvent;
use App\Support\Tenancy\Schemas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the AI spend dashboards. The org-facing view queries
 * ai_usage_events through the tenant connection (RLS auto-scopes it to the
 * caller's org); the platform view reads cross-org via the owner connection
 * (which bypasses RLS) so a sysadmin sees every organization at once.
 */
class AiUsageReport
{
    /**
     * Spend for the current tenant (RLS-scoped) over the last $days days.
     *
     * @return array<string, mixed>
     */
    public function forCurrentOrg(int $days = 30): array
    {
        $since = Carbon::today()->subDays($days - 1);

        $rows = AiUsageEvent::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) as d, source, model, cost, input_tokens, output_tokens')
            ->get();

        return $this->shape($rows, $since, $days);
    }

    /**
     * Platform-wide spend across every org for the sysadmin view, plus a
     * per-organization breakdown. The two sources are read separately and merged
     * so each meter is authoritative:
     *   - `system` spend (what the platform pays) from platform.system_ai_usage_events,
     *     which includes calls with no tenant attribution;
     *   - `own` (BYOK) spend from tenant.ai_usage_events via the owner connection
     *     (RLS bypassed) so every org's own-key usage is visible too.
     *
     * @return array<string, mixed>
     */
    public function platformWide(int $days = 30): array
    {
        $since = Carbon::today()->subDays($days - 1);

        $ownRows = DB::connection('pgsql')->table(Schemas::qualify('ai_usage_events'))
            ->where('source', 'own')
            ->where('created_at', '>=', $since)
            ->selectRaw("date(created_at) as d, 'own' as source, model, organization_id, cost, input_tokens, output_tokens")
            ->get();

        $systemRows = DB::connection('pgsql')->table('platform.system_ai_usage_events')
            ->where('created_at', '>=', $since)
            ->selectRaw("date(created_at) as d, 'system' as source, model, organization_id, cost, input_tokens, output_tokens")
            ->get();

        $rows = $ownRows->concat($systemRows);

        $report = $this->shape($rows, $since, $days);

        // Top organizations by spend (system spend is what the platform pays).
        $report['by_org'] = collect($rows)
            ->groupBy('organization_id')
            ->map(fn ($g, $org) => [
                'organization_id' => $org ?: null,
                'cost' => round((float) $g->sum('cost'), 4),
                'system_cost' => round((float) $g->where('source', 'system')->sum('cost'), 4),
                'calls' => $g->count(),
            ])
            ->sortByDesc('cost')
            ->take(20)
            ->values()
            ->all();

        return $report;
    }

    /**
     * Shared shaping of a flat row set into totals + breakdowns + a daily series.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function shape($rows, Carbon $since, int $days): array
    {
        $rows = collect($rows);

        $bySource = fn (string $source) => round((float) $rows->where('source', $source)->sum('cost'), 4);

        // Daily cost series split by source, zero-filled across the window so the
        // chart has a point per day.
        $byDay = $rows->groupBy('d');
        $labels = [];
        $ownSeries = [];
        $systemSeries = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $since->copy()->addDays($i)->toDateString();
            $labels[] = $day;
            $dayRows = $byDay->get($day) ?? collect();
            $ownSeries[] = round((float) collect($dayRows)->where('source', 'own')->sum('cost'), 4);
            $systemSeries[] = round((float) collect($dayRows)->where('source', 'system')->sum('cost'), 4);
        }

        $byModel = $rows->groupBy('model')
            ->map(fn ($g, $model) => [
                'model' => $model,
                'cost' => round((float) $g->sum('cost'), 4),
                'calls' => $g->count(),
                'input_tokens' => (int) $g->sum('input_tokens'),
                'output_tokens' => (int) $g->sum('output_tokens'),
            ])
            ->sortByDesc('cost')
            ->take(15)
            ->values()
            ->all();

        return [
            'range_days' => $days,
            'totals' => [
                'cost' => round((float) $rows->sum('cost'), 4),
                'calls' => $rows->count(),
                'input_tokens' => (int) $rows->sum('input_tokens'),
                'output_tokens' => (int) $rows->sum('output_tokens'),
            ],
            'by_source' => [
                'own' => $bySource('own'),
                'system' => $bySource('system'),
            ],
            'by_model' => $byModel,
            'series' => [
                'labels' => $labels,
                'own' => $ownSeries,
                'system' => $systemSeries,
            ],
        ];
    }
}
