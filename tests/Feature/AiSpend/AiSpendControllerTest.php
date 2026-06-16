<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
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
