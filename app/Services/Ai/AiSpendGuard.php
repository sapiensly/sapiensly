<?php

namespace App\Services\Ai;

use App\Exceptions\AiBudgetExceededException;
use App\Facades\TenantCache;
use App\Models\AiUsageEvent;
use App\Models\OrganizationAiBudget;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The propose-don't-overspend gate (phase 2): called BEFORE each AI model call,
 * it hard-blocks the call when the org has reached its budget for that source.
 * System spend (platform pays) is capped by the org budget AND the platform
 * ceiling; own/BYOK spend is capped only if the org opted in. The current-period
 * spend is summed from ai_usage_events (RLS-scoped to the acting org) and memoed
 * briefly in the tenant cache. Fail-open: any error in the guard itself allows
 * the call — a broken meter must never take the product down.
 */
class AiSpendGuard
{
    private const SPEND_CACHE_TTL = 60;

    public function __construct(private readonly AiSourceResolver $sources) {}

    /**
     * @throws AiBudgetExceededException when the period budget for the call's source is exhausted
     */
    public function assertWithinBudget(?User $user, ?string $organizationId, string $model): void
    {
        $organizationId ??= $user?->organization_id;
        if ($organizationId === null) {
            return; // personal context: no org budget to enforce (v1)
        }

        $budget = OrganizationAiBudget::query()->where('organization_id', $organizationId)->first();
        if ($budget === null || ! $budget->enforcement_enabled) {
            return;
        }

        $source = $this->sources->source($model, $user);
        $limit = $budget->effectiveLimit($source);
        if ($limit === null) {
            return; // uncapped for this source
        }

        $spent = $this->currentSpend($source, $budget->reset_day);
        if ($spent !== null && $spent >= $limit) {
            throw new AiBudgetExceededException($source, $spent, $limit);
        }
    }

    /**
     * Period-to-date spend for a source for the current tenant (RLS-scoped),
     * memoed per org. Returns null on any failure so the caller fails open.
     */
    private function currentSpend(string $source, int $resetDay): ?float
    {
        $start = $this->periodStart($resetDay);

        try {
            return (float) TenantCache::remember(
                "ai_spend_sum:{$source}:{$start->toDateString()}",
                self::SPEND_CACHE_TTL,
                fn () => (float) AiUsageEvent::query()
                    ->where('source', $source)
                    ->where('created_at', '>=', $start)
                    ->sum('cost'),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Start of the current budget period given the reset day-of-month.
     */
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
