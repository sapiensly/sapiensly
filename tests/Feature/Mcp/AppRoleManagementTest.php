<?php

use App\Enums\MembershipRole;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\AssignAppRoleTool;
use App\Mcp\Tools\Build\ListAppRolesTool;
use App\Mcp\Tools\Build\RevokeAppRoleTool;
use App\Models\App;
use App\Models\AppUserRole;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The app-access MCP tools (list/assign/revoke roles): admin-gated, member by
 * email, validated against the manifest roles + the org roster. The same
 * AppRoleAssignmentService the builder's Access panel uses.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg();
    $this->owner = mcpMember($this->org, MembershipRole::Owner);
    $this->member = mcpMember($this->org, MembershipRole::Member);

    // initialManifest ships admin (non-default) + user (default) roles.
    $this->testApp = App::factory()->create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'slug' => 'rolesapp',
        'visibility' => 'organization',
    ]);
    $manifests = app(AppManifestService::class);
    $manifests->createVersion($this->testApp, $manifests->initialManifest($this->testApp), $this->owner, 'seed');
});

it('assigns a manifest role to a member by email', function () {
    SapiensServer::actingAs($this->owner)
        ->tool(AssignAppRoleTool::class, [
            'app_slug' => 'rolesapp',
            'user_email' => $this->member->email,
            'role_slug' => 'admin',
        ])
        ->assertOk()
        ->assertSee('assigned');

    expect(AppUserRole::query()
        ->where('app_id', $this->testApp->id)
        ->where('assigned_user_id', $this->member->id)
        ->value('role_slug'))->toBe('admin');
});

it('lists the access roster with the member current role', function () {
    AppUserRole::factory()->create([
        'app_id' => $this->testApp->id, 'assigned_user_id' => $this->member->id, 'role_slug' => 'admin',
    ]);

    SapiensServer::actingAs($this->owner)
        ->tool(ListAppRolesTool::class, ['app_slug' => 'rolesapp'])
        ->assertOk()
        ->assertSee('open')                 // access_mode
        ->assertSee($this->member->email)
        ->assertSee('admin');
});

it('revokes a member explicit role', function () {
    AppUserRole::factory()->create([
        'app_id' => $this->testApp->id, 'assigned_user_id' => $this->member->id, 'role_slug' => 'admin',
    ]);

    SapiensServer::actingAs($this->owner)
        ->tool(RevokeAppRoleTool::class, [
            'app_slug' => 'rolesapp',
            'user_email' => $this->member->email,
        ])
        ->assertOk()
        ->assertSee('revoked');

    expect(AppUserRole::query()->where('app_id', $this->testApp->id)->count())->toBe(0);
});

it('rejects a role slug not in the manifest', function () {
    SapiensServer::actingAs($this->owner)
        ->tool(AssignAppRoleTool::class, [
            'app_slug' => 'rolesapp',
            'user_email' => $this->member->email,
            'role_slug' => 'ghost',
        ])
        ->assertHasErrors();

    expect(AppUserRole::query()->where('app_id', $this->testApp->id)->count())->toBe(0);
});

it('forbids a non-admin member from managing access', function () {
    SapiensServer::actingAs($this->member)
        ->tool(AssignAppRoleTool::class, [
            'app_slug' => 'rolesapp',
            'user_email' => $this->member->email,
            'role_slug' => 'admin',
        ])
        ->assertHasErrors();
});
