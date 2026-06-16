<?php

use App\Models\AiUsageEvent;
use App\Models\Organization;
use App\Models\OrganizationAiBudget;

/**
 * Phase 2 — the budget alert command emits a threshold alert once per
 * org/period/source/level and de-duplicates on repeat runs.
 */
it('emits a threshold alert when system spend crosses the budget, once', function () {
    $org = Organization::create(['name' => 'Acme']);
    OrganizationAiBudget::create([
        'organization_id' => $org->id,
        'system_monthly_budget' => 10,
        'alert_threshold_pct' => 80,
    ]);
    AiUsageEvent::create([
        'organization_id' => $org->id,
        'module' => 'chat',
        'driver' => 'anthropic',
        'model' => 'claude-x',
        'source' => 'system',
        'cost' => 9, // 90% of 10 → crosses the 80% threshold
        'status' => 'success',
    ]);

    $this->artisan('ai-spend:check-budgets')
        ->expectsOutputToContain('emitted 1 alert')
        ->assertSuccessful();

    // De-duped: a second run in the same period emits nothing new.
    $this->artisan('ai-spend:check-budgets')
        ->expectsOutputToContain('emitted 0 alert')
        ->assertSuccessful();
});

it('does not alert when under threshold', function () {
    $org = Organization::create(['name' => 'Acme']);
    OrganizationAiBudget::create([
        'organization_id' => $org->id,
        'system_monthly_budget' => 100,
        'alert_threshold_pct' => 80,
    ]);
    AiUsageEvent::create([
        'organization_id' => $org->id,
        'module' => 'chat', 'driver' => 'anthropic', 'model' => 'claude-x',
        'source' => 'system', 'cost' => 5, 'status' => 'success',
    ]);

    $this->artisan('ai-spend:check-budgets')
        ->expectsOutputToContain('emitted 0 alert')
        ->assertSuccessful();
});
