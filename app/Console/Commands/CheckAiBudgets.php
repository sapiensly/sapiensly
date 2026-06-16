<?php

namespace App\Console\Commands;

use App\Models\OrganizationAiBudget;
use App\Support\Tenancy\Schemas;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Surfaces AI-spend budget alerts: for each org with a budget, compares the
 * current period's system + own spend against its effective limit and logs a
 * warning when usage crosses the org's alert threshold (and again at 100%).
 * De-duplicated per org/period/level so a daily run doesn't repeat the same
 * alert. (The dashboard shows live budget usage; this is the ops/automation
 * hook to drive notifications.)
 */
class CheckAiBudgets extends Command
{
    protected $signature = 'ai-spend:check-budgets';

    protected $description = 'Check organization AI spend against budgets and emit threshold alerts';

    public function handle(): int
    {
        $budgets = OrganizationAiBudget::query()->get();
        $alerts = 0;

        foreach ($budgets as $budget) {
            $start = $this->periodStart($budget->reset_day);

            foreach (['system', 'own'] as $source) {
                $limit = $budget->effectiveLimit($source);
                if ($limit === null || $limit <= 0) {
                    continue;
                }

                $spent = $this->spendFor($budget->organization_id, $source, $start);
                $pct = (int) floor(($spent / $limit) * 100);

                $level = match (true) {
                    $pct >= 100 => 100,
                    $pct >= $budget->alert_threshold_pct => $budget->alert_threshold_pct,
                    default => null,
                };

                if ($level !== null && $this->shouldAlert($budget->organization_id, $source, $start, $level)) {
                    $alerts++;
                    Log::warning('AI spend budget alert', [
                        'organization_id' => $budget->organization_id,
                        'source' => $source,
                        'spent' => round($spent, 4),
                        'limit' => $limit,
                        'pct' => $pct,
                        'level' => $level,
                    ]);
                }
            }
        }

        $this->info("Checked {$budgets->count()} budget(s); emitted {$alerts} alert(s).");

        return self::SUCCESS;
    }

    private function spendFor(string $organizationId, string $source, Carbon $start): float
    {
        return (float) DB::connection('pgsql')->table(Schemas::qualify('ai_usage_events'))
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->where('created_at', '>=', $start)
            ->sum('cost');
    }

    /**
     * Fire each (org, period, source, level) alert at most once.
     */
    private function shouldAlert(string $organizationId, string $source, Carbon $start, int $level): bool
    {
        $key = "ai_budget_alert:{$organizationId}:{$source}:{$start->toDateString()}:{$level}";

        return Cache::add($key, true, now()->addDays(40));
    }

    private function periodStart(int $resetDay): Carbon
    {
        $resetDay = max(1, min(28, $resetDay));
        $today = Carbon::today();
        $start = $today->copy()->day($resetDay);

        if ($today->day < $resetDay) {
            $start->subMonthNoOverflow();
        }

        return $start;
    }
}
