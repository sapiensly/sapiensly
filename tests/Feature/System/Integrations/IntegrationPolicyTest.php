<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Policies\IntegrationPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $this->policy = new IntegrationPolicy;
});

function makeOrgWithUser(string $role = 'member'): array
{
    $org = Organization::create(['name' => 'O', 'slug' => 'o-'.uniqid()]);
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => $role === 'owner' ? MembershipRole::Owner : MembershipRole::Member,
        'status' => MembershipStatus::Active,
    ]);
    // Observer has set the team context to this org; re-assert for clarity.
    setPermissionsTeamId($org->id);

    return [$org, $user];
}

test('personal-mode user can view, create, update, delete their own integration', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->forUser($user)->create();

    expect($this->policy->viewAny($user))->toBeTrue()
        ->and($this->policy->view($user, $integration))->toBeTrue()
        ->and($this->policy->create($user))->toBeTrue()
        ->and($this->policy->update($user, $integration))->toBeTrue()
        ->and($this->policy->delete($user, $integration))->toBeTrue()
        ->and($this->policy->execute($user, $integration))->toBeTrue();
});

test('personal-mode user cannot update or delete another users integration', function () {
    $owner = User::factory()->create(['organization_id' => null]);
    $other = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->forUser($owner)->create();

    expect($this->policy->update($other, $integration))->toBeFalse()
        ->and($this->policy->delete($other, $integration))->toBeFalse();
});

test('business-mode member can view + execute organization integrations but not update others', function () {
    [$org, $owner] = makeOrgWithUser('owner');
    $member = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $member->id,
        'role' => MembershipRole::Member,
        'status' => MembershipStatus::Active,
    ]);
    setPermissionsTeamId($org->id);

    $integration = Integration::factory()->forOrganization($org, $owner)->create();

    expect($this->policy->view($member, $integration))->toBeTrue()
        ->and($this->policy->execute($member, $integration))->toBeTrue()
        ->and($this->policy->update($member, $integration))->toBeFalse()
        ->and($this->policy->delete($member, $integration))->toBeFalse();
});

test('user cannot view integrations from another organization', function () {
    [$orgA, $userA] = makeOrgWithUser();
    [$orgB, $userB] = makeOrgWithUser();

    $integrationA = Integration::factory()->forOrganization($orgA, $userA)->create();

    setPermissionsTeamId($orgB->id);
    expect($this->policy->view($userB, $integrationA))->toBeFalse()
        ->and($this->policy->execute($userB, $integrationA))->toBeFalse();
});

test('global integrations are visible to every authenticated user', function () {
    $global = Integration::factory()->global()->create();

    $userA = User::factory()->create(['organization_id' => null]);
    [$orgB, $userB] = makeOrgWithUser('member');

    setPermissionsTeamId(null);
    expect($this->policy->view($userA, $global))->toBeTrue()
        ->and($this->policy->execute($userA, $global))->toBeTrue();

    setPermissionsTeamId($orgB->id);
    expect($this->policy->view($userB, $global))->toBeTrue()
        ->and($this->policy->execute($userB, $global))->toBeTrue();
});

test('only sysadmin can update or delete a global integration', function () {
    $global = Integration::factory()->global()->create();

    $sysadmin = User::factory()->create();
    setPermissionsTeamId(null);
    $sysadmin->assignRole('sysadmin');

    [$org, $owner] = makeOrgWithUser('owner');

    // Check sysadmin ability under null team context (where sysadmin role lives).
    setPermissionsTeamId(null);
    expect($this->policy->manageGlobal($sysadmin))->toBeTrue();

    // Check that a business-mode owner cannot manage global integrations.
    setPermissionsTeamId($org->id);
    expect($this->policy->manageGlobal($owner))->toBeFalse()
        ->and($this->policy->update($owner, $global))->toBeFalse()
        ->and($this->policy->delete($owner, $global))->toBeFalse();
});

test('viewAny respects the organizations view permission', function () {
    [$orgA, $memberWithPerm] = makeOrgWithUser('member');

    expect($this->policy->viewAny($memberWithPerm))->toBeTrue();

    // Strip the role entirely — permissions come through the role in this app.
    [$orgB, $strippedUser] = makeOrgWithUser('member');
    setPermissionsTeamId($orgB->id);
    $strippedUser->syncRoles([]);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    expect($this->policy->viewAny($strippedUser))->toBeFalse();
});

test('create respects the organizations create permission in business mode', function () {
    [$org, $member] = makeOrgWithUser('member');

    expect($this->policy->create($member))->toBeTrue();

    $member->syncRoles([]);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    expect($this->policy->create($member))->toBeFalse();
});
