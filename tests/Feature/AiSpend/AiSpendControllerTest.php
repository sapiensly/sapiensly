<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AiUsageEvent;
use App\Models\Organization;
use App\Models\OrganizationAiBudget;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Org-facing AI spend dashboard access: only an active org owner sees their org's
 * spend; a regular member is forbidden; a personal user sees their own.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});
function orgMember(string $role, ?Organization $org = null): array
{
    $org ??= Organization::create(['name' => 'Acme']);
    $user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => $role,
        'status' => MembershipStatus::Active->value,
    ]);

    return [$org, $user];
}

it('redirects guests', function () {
    $this->get('/system/ai-spend')->assertRedirect('/login');
});

it('shows the dashboard to an active org owner', function () {
    [, $owner] = orgMember(MembershipRole::Owner->value);

    $this->actingAs($owner)
        ->get('/system/ai-spend')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('system/AiSpend/Dashboard')
            ->where('scope.type', 'organization'));
});

it('offers the calendar periods and honours the picked one', function () {
    [, $owner] = orgMember(MembershipRole::Owner->value);

    $this->actingAs($owner)
        ->get('/system/ai-spend?period=today')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('period.key', 'today')
            ->where('period.granularity', 'hour')
            ->where('report.period.key', 'today')
            ->where('periods.0.key', 'today')
            ->where('periods.1.key', 'week')
            ->where('periods.2.key', 'month'));
});

it('falls back to the default window for a stale or bogus period', function () {
    [, $owner] = orgMember(MembershipRole::Owner->value);

    $this->actingAs($owner)
        ->get('/system/ai-spend?period=since-forever')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('period.key', '30d'));
});

it('still resolves a legacy days link', function () {
    [, $owner] = orgMember(MembershipRole::Owner->value);

    $this->actingAs($owner)
        ->get('/system/ai-spend?days=7')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('period.key', '7d'));
});

it('measures the budget meter against the budget month, not the picked period', function () {
    [$org, $owner] = orgMember(MembershipRole::Owner->value);
    OrganizationAiBudget::create([
        'organization_id' => $org->id,
        'system_monthly_budget' => 100,
        'alert_threshold_pct' => 80,
        'enforcement_enabled' => true,
        'reset_day' => 1,
    ]);

    $this->travelTo('2026-07-02 10:00:00');
    AiUsageEvent::create([
        'organization_id' => $org->id,
        'module' => 'chat',
        'driver' => 'anthropic',
        'model' => 'claude-test',
        'source' => 'system',
        'input_tokens' => 10,
        'output_tokens' => 10,
        'cost' => 12.5,
        'estimated' => false,
        'status' => 'success',
    ]);
    $this->travelTo('2026-07-20 10:00:00');

    // The 2nd is outside a "today" window, but it IS inside the budget month —
    // the meter has to keep showing it or the cap reads as untouched.
    $this->actingAs($owner)
        ->get('/system/ai-spend?period=today')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.by_source.system', 0)
            ->where('budget.period_to_date.system', 12.5)
            ->where('budget.period_to_date.since', '2026-07-01'));
});

it('forbids a non-owner member', function () {
    [, $member] = orgMember(MembershipRole::Member->value);

    $this->actingAs($member)->get('/system/ai-spend')->assertForbidden();
});

it('lets an owner set their org budget', function () {
    [$org, $owner] = orgMember(MembershipRole::Owner->value);

    $this->actingAs($owner)->post('/system/ai-spend/budget', [
        'system_monthly_budget' => 50,
        'own_monthly_budget' => null,
        'alert_threshold_pct' => 75,
        'enforcement_enabled' => true,
    ])->assertRedirect();

    $this->assertDatabaseHas('organization_ai_budgets', [
        'organization_id' => $org->id,
        'system_monthly_budget' => 50,
        'alert_threshold_pct' => 75,
    ]);
});

it('forbids a member from setting the org budget', function () {
    [, $member] = orgMember(MembershipRole::Member->value);

    $this->actingAs($member)->post('/system/ai-spend/budget', [
        'alert_threshold_pct' => 80,
        'enforcement_enabled' => true,
    ])->assertForbidden();
});

it('shows a personal user their own spend', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get('/system/ai-spend')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scope.type', 'personal'));
});
