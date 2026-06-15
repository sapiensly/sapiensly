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

it('shows a personal user their own spend', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get('/system/ai-spend')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scope.type', 'personal'));
});
