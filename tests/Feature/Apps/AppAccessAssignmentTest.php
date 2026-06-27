<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\App;
use App\Models\AppUserRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 4 — the app access management surface (AppAccessController +
 * AppRoleAssignmentService): only an app/org admin may list and assign roles,
 * assignments are validated against the manifest roles and the org roster, and a
 * revoke drops the member back to the default role.
 */
function aaa_member(Organization $org, MembershipRole $role): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id, 'user_id' => $user->id,
        'role' => $role, 'status' => MembershipStatus::Active,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->owner = aaa_member($this->org, MembershipRole::Owner);
    $this->member = aaa_member($this->org, MembershipRole::Member);

    $this->testApp = App::create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'slug' => 'accessapp',
        'name' => 'Access App',
        'visibility' => 'organization',
    ]);

    app(AppManifestService::class)->createVersion($this->testApp, [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'accessapp',
        'name' => 'Access App',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => [
            'roles' => [
                ['id' => 'rol_adminrole', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
                ['id' => 'rol_userrole', 'slug' => 'user', 'name' => 'User', 'is_default' => true],
            ],
        ],
    ], $this->owner);
});

it('lets an app admin read the roster with manifest roles', function () {
    $this->actingAs($this->owner)
        ->getJson("/apps/{$this->testApp->id}/access")
        ->assertOk()
        ->assertJsonPath('access_mode', 'open')
        ->assertJsonCount(2, 'roles')
        ->assertJsonCount(2, 'members'); // owner + member
});

it('forbids a non-admin member from managing access', function () {
    $this->actingAs($this->member)
        ->getJson("/apps/{$this->testApp->id}/access")
        ->assertForbidden();

    $this->actingAs($this->member)
        ->postJson("/apps/{$this->testApp->id}/access", [
            'assigned_user_id' => $this->member->id, 'role_slug' => 'admin',
        ])
        ->assertForbidden();
});

it('assigns a manifest role to an org member', function () {
    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access", [
            'assigned_user_id' => $this->member->id, 'role_slug' => 'admin',
        ])
        ->assertOk();

    expect(AppUserRole::query()
        ->where('app_id', $this->testApp->id)
        ->where('assigned_user_id', $this->member->id)
        ->value('role_slug'))->toBe('admin');
});

it('replaces an existing assignment rather than duplicating it', function () {
    AppUserRole::factory()->create([
        'app_id' => $this->testApp->id, 'assigned_user_id' => $this->member->id, 'role_slug' => 'user',
    ]);

    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access", [
            'assigned_user_id' => $this->member->id, 'role_slug' => 'admin',
        ])
        ->assertOk();

    expect(AppUserRole::query()->where('app_id', $this->testApp->id)->count())->toBe(1)
        ->and(AppUserRole::query()->where('app_id', $this->testApp->id)->value('role_slug'))->toBe('admin');
});

it('rejects a role slug not defined in the manifest', function () {
    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access", [
            'assigned_user_id' => $this->member->id, 'role_slug' => 'ghost',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('role_slug');
});

it('rejects assigning a user outside the app organization', function () {
    $outsider = User::factory()->create();

    $this->actingAs($this->owner)
        ->postJson("/apps/{$this->testApp->id}/access", [
            'assigned_user_id' => $outsider->id, 'role_slug' => 'user',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('assigned_user_id');
});

it('revokes an assignment, dropping the member back to the default role', function () {
    $assignment = AppUserRole::factory()->create([
        'app_id' => $this->testApp->id, 'assigned_user_id' => $this->member->id, 'role_slug' => 'admin',
    ]);

    $this->actingAs($this->owner)
        ->deleteJson("/apps/{$this->testApp->id}/access/{$assignment->id}")
        ->assertOk();

    expect(AppUserRole::query()->whereKey($assignment->id)->exists())->toBeFalse();
});
