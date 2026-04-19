<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\Visibility;
use App\Models\Agent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

function createOrgWithOwner(): array
{
    $org = Organization::create([
        'name' => 'Test Org',
        'slug' => 'test-org',
    ]);

    $owner = User::factory()->create(['organization_id' => $org->id]);

    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);

    return [$org, $owner];
}

function createOrgMember(Organization $org): User
{
    $member = User::factory()->create(['organization_id' => $org->id]);

    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $member->id,
        'role' => MembershipRole::Member,
        'status' => MembershipStatus::Active,
    ]);

    return $member;
}

function createSysAdmin(): User
{
    $sysadmin = User::factory()->create();

    // SysAdmin role is global (no team context)
    setPermissionsTeamId(null);
    $sysadmin->assignRole('sysadmin');

    return $sysadmin;
}

// --- Seeder Tests ---

test('seeder creates all roles and permissions', function () {
    expect(Role::count())->toBe(3);
    expect(Permission::count())->toBe(51);
});

test('sysadmin role has all permissions', function () {
    $sysAdminRole = Role::findByName('sysadmin', 'web');
    expect($sysAdminRole->permissions->count())->toBe(51);
});

test('owner role has all permissions', function () {
    $ownerRole = Role::findByName('owner', 'web');
    expect($ownerRole->permissions->count())->toBe(51);
});

test('member role lacks org management permissions', function () {
    $memberRole = Role::findByName('member', 'web');

    expect($memberRole->hasPermissionTo('organization.manage'))->toBeFalse();
    expect($memberRole->hasPermissionTo('organization.invite-members'))->toBeFalse();
    expect($memberRole->hasPermissionTo('organization.remove-members'))->toBeFalse();
    expect($memberRole->hasPermissionTo('agents.view'))->toBeTrue();
    expect($memberRole->hasPermissionTo('agents.create'))->toBeTrue();
});

// --- Observer Tests ---

test('observer assigns spatie role when membership is created', function () {
    [$org, $owner] = createOrgWithOwner();

    setPermissionsTeamId($org->id);
    expect($owner->hasRole('owner'))->toBeTrue();
});

test('observer syncs role when membership role changes', function () {
    [$org, $owner] = createOrgWithOwner();

    $membership = OrganizationMembership::where('user_id', $owner->id)->first();
    $membership->update(['role' => MembershipRole::Member]);

    $owner->unsetRelation('roles');
    setPermissionsTeamId($org->id);
    expect($owner->hasRole('member'))->toBeTrue();
    expect($owner->hasRole('owner'))->toBeFalse();
});

test('observer removes roles when membership is deleted', function () {
    [$org, $owner] = createOrgWithOwner();

    OrganizationMembership::where('user_id', $owner->id)->first()->delete();

    $owner->unsetRelation('roles');
    setPermissionsTeamId($org->id);
    expect($owner->getRoleNames()->isEmpty())->toBeTrue();
});

// --- Personal Mode Tests ---

test('personal mode user can view own agent without permissions', function () {
    $user = User::factory()->create(['organization_id' => null]);

    $agent = Agent::factory()->create([
        'user_id' => $user->id,
        'organization_id' => null,
        'visibility' => Visibility::Private,
    ]);

    $response = $this->actingAs($user)->get(route('agents.show', $agent));
    $response->assertOk();
});

// --- Org Mode Permission Tests ---

test('owner can view org agents', function () {
    [$org, $owner] = createOrgWithOwner();

    $agent = Agent::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'visibility' => Visibility::Organization,
    ]);

    setPermissionsTeamId($org->id);

    $response = $this->actingAs($owner)->get(route('agents.show', $agent));
    $response->assertOk();
});

test('member can view org agents', function () {
    [$org, $owner] = createOrgWithOwner();
    $member = createOrgMember($org);

    $agent = Agent::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'visibility' => Visibility::Organization,
    ]);

    setPermissionsTeamId($org->id);

    $response = $this->actingAs($member)->get(route('agents.show', $agent));
    $response->assertOk();
});

// --- SysAdmin Tests ---

test('sysadmin bypasses all authorization', function () {
    [$org, $owner] = createOrgWithOwner();
    $sysadmin = createSysAdmin();
    $sysadmin->update(['organization_id' => $org->id]);

    // Create org membership for sysadmin so they have org context
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $sysadmin->id,
        'role' => MembershipRole::Member,
        'status' => MembershipStatus::Active,
    ]);

    $agent = Agent::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'visibility' => Visibility::Organization,
    ]);

    // SysAdmin can view agent even though not the owner
    $response = $this->actingAs($sysadmin)->get(route('agents.show', $agent));
    $response->assertOk();
});

test('sysadmin role is shared via inertia', function () {
    $sysadmin = createSysAdmin();

    $response = $this->actingAs($sysadmin)->get(route('dashboard'));
    $response->assertOk();

    $page = $response->original->getData()['page'];
    $auth = $page['props']['auth'];

    expect($auth['roles'])->toContain('sysadmin');
});

// --- Organization Invite Permission Tests ---

test('owner can invite members', function () {
    [$org, $owner] = createOrgWithOwner();
    $invitee = User::factory()->create();

    setPermissionsTeamId($org->id);

    $response = $this->actingAs($owner)->post(route('organization.invite'), [
        'email' => $invitee->email,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
});

test('member cannot invite members', function () {
    [$org, $owner] = createOrgWithOwner();
    $member = createOrgMember($org);
    $invitee = User::factory()->create();

    setPermissionsTeamId($org->id);

    $response = $this->actingAs($member)->post(route('organization.invite'), [
        'email' => $invitee->email,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('email');
});

// --- Middleware Tests ---

test('middleware sets team context from user organization', function () {
    [$org, $owner] = createOrgWithOwner();

    $this->actingAs($owner)->get(route('dashboard'));

    expect(getPermissionsTeamId())->toBe($org->id);
});

test('middleware sets null team context for personal mode', function () {
    $user = User::factory()->create(['organization_id' => null]);

    $this->actingAs($user)->get(route('dashboard'));

    expect(getPermissionsTeamId())->toBeNull();
});

// --- Inertia Shared Data Tests ---

test('permissions are shared via inertia for org users', function () {
    [$org, $owner] = createOrgWithOwner();

    $response = $this->actingAs($owner)->get(route('dashboard'));
    $response->assertOk();

    $page = $response->original->getData()['page'];
    $auth = $page['props']['auth'];

    expect($auth['permissions'])->not->toBeEmpty();
    expect($auth['roles'])->toContain('owner');
});

test('permissions are empty for personal mode users', function () {
    $user = User::factory()->create(['organization_id' => null]);

    $response = $this->actingAs($user)->get(route('dashboard'));
    $response->assertOk();

    $page = $response->original->getData()['page'];
    $auth = $page['props']['auth'];

    expect($auth['permissions'])->toBeEmpty();
    expect($auth['roles'])->toBeEmpty();
});
