<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's AI spend budget (phase 2). Platform config that drives the
 * spend guard. system/own monthly budgets are org-set; platform_system_cap is a
 * sysadmin-imposed hard ceiling on system spend.
 */
class OrganizationAiBudget extends Model
{
    use UsesPlatformConnection;

    protected $fillable = [
        'organization_id',
        'system_monthly_budget',
        'own_monthly_budget',
        'platform_system_cap',
        'alert_threshold_pct',
        'reset_day',
        'enforcement_enabled',
    ];

    protected function casts(): array
    {
        return [
            'system_monthly_budget' => 'float',
            'own_monthly_budget' => 'float',
            'platform_system_cap' => 'float',
            'alert_threshold_pct' => 'integer',
            'reset_day' => 'integer',
            'enforcement_enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The effective hard cap for a spend source: system is the lower of the
     * org's system budget and the platform ceiling; own is the org's own budget.
     * Null means uncapped.
     */
    public function effectiveLimit(string $source): ?float
    {
        if ($source === 'own') {
            return $this->own_monthly_budget;
        }

        return collect([$this->system_monthly_budget, $this->platform_system_cap])
            ->filter(fn ($v) => $v !== null)
            ->min();
    }
}
