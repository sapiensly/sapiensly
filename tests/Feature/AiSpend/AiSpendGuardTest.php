<?php

use App\Exceptions\AiBudgetExceededException;
use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use App\Models\Organization;
use App\Models\OrganizationAiBudget;
use App\Models\User;
use App\Services\Ai\AiSpendGuard;
use App\Services\LLMService;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 2 — the spend gate. assertWithinBudget hard-blocks a call once the org's
 * period spend reaches the budget for that source; system spend is capped by the
 * org budget AND the platform ceiling; own spend only if the org opts in.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme']);
    $this->user = User::factory()->create(['organization_id' => $this->org->id]);
    // The guard sums RLS-scoped events + memoes per org in the tenant cache, so
    // the request scope must be set (as it is in HTTP/queue at a real call site).
    app(TenantContext::class)->set($this->org->id, null);
});

function guardEvent(string $org, string $source, float $cost): void
{
    AiUsageEvent::create([
        'organization_id' => $org,
        'module' => 'chat',
        'driver' => 'anthropic',
        'model' => 'claude-x',
        'source' => $source,
        'cost' => $cost,
        'status' => 'success',
    ]);
}

it('allows when the org has no budget', function () {
    app(AiSpendGuard::class)->assertWithinBudget($this->user, $this->org->id, 'claude-x');
})->throwsNoExceptions();

it('allows when system spend is under budget', function () {
    OrganizationAiBudget::create(['organization_id' => $this->org->id, 'system_monthly_budget' => 10]);
    guardEvent($this->org->id, 'system', 3);

    app(AiSpendGuard::class)->assertWithinBudget($this->user, $this->org->id, 'claude-x');
})->throwsNoExceptions();

it('blocks when system spend reaches the budget', function () {
    OrganizationAiBudget::create(['organization_id' => $this->org->id, 'system_monthly_budget' => 5]);
    guardEvent($this->org->id, 'system', 6);

    app(AiSpendGuard::class)->assertWithinBudget($this->user, $this->org->id, 'claude-x');
})->throws(AiBudgetExceededException::class);

it('lets the platform cap win over a higher org system budget', function () {
    OrganizationAiBudget::create([
        'organization_id' => $this->org->id,
        'system_monthly_budget' => 100,
        'platform_system_cap' => 2,
    ]);
    guardEvent($this->org->id, 'system', 3);

    app(AiSpendGuard::class)->assertWithinBudget($this->user, $this->org->id, 'claude-x');
})->throws(AiBudgetExceededException::class);

it('does not enforce when enforcement is disabled', function () {
    OrganizationAiBudget::create([
        'organization_id' => $this->org->id,
        'system_monthly_budget' => 1,
        'enforcement_enabled' => false,
    ]);
    guardEvent($this->org->id, 'system', 50);

    app(AiSpendGuard::class)->assertWithinBudget($this->user, $this->org->id, 'claude-x');
})->throwsNoExceptions();

it('blocks a real call site (LLMService) once over budget', function () {
    OrganizationAiBudget::create(['organization_id' => $this->org->id, 'system_monthly_budget' => 1]);
    guardEvent($this->org->id, 'system', 5);

    $llm = app(LLMService::class)->setContext($this->user);

    expect(fn () => $llm->getProvider('claude-x'))
        ->toThrow(AiBudgetExceededException::class);
});

it('does not cap own (BYOK) spend unless the org opts in', function () {
    // Make the model resolve to an OWN source: the org owns the anthropic driver.
    AiCatalogModel::create([
        'driver' => 'anthropic', 'model_id' => 'claude-x', 'label' => 'X',
        'capability' => 'chat', 'is_enabled' => true, 'sort_order' => 0,
    ]);
    AiProvider::create([
        'user_id' => $this->user->id, 'organization_id' => $this->org->id, 'visibility' => 'organization',
        'name' => 'anthropic', 'driver' => 'anthropic', 'display_name' => 'Anthropic',
        'credentials' => ['api_key' => 'k'], 'status' => 'active',
    ]);
    OrganizationAiBudget::create(['organization_id' => $this->org->id, 'system_monthly_budget' => 1]); // own_monthly_budget null
    guardEvent($this->org->id, 'own', 999);

    app(AiSpendGuard::class)->assertWithinBudget($this->user, $this->org->id, 'claude-x');
})->throwsNoExceptions();
