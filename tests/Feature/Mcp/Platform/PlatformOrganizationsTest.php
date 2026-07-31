<?php

use App\Enums\MembershipRole;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Platform\CreateOrganizationTool;
use App\Mcp\Tools\Platform\InspectOrganizationTool;
use App\Mcp\Tools\Platform\ListOrganizationsTool;
use App\Mcp\Tools\Platform\ManageOrganizationTool;
use App\Mcp\Tools\Platform\SetOrganizationBudgetTool;
use App\Models\Organization;
use App\Models\OrganizationAiBudget;
use App\Models\PlatformAuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg('Acme');
    $this->sysadmin = mcpSysadmin($this->org);
    mcpActingContext(['platform:admin']);
});

it('lists every organization, searchable', function () {
    mcpOrg('Globex');

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ListOrganizationsTool::class, ['search' => 'globex'])
        ->assertOk()
        ->assertSee('Globex');
});

it('inspects one organization by slug, with members and spend', function () {
    mcpMember($this->org, MembershipRole::Member);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(InspectOrganizationTool::class, ['organization' => $this->org->slug])
        ->assertOk()
        ->assertSee('members')
        ->assertSee('built')
        ->assertSee($this->org->id);
});

it('reports a helpful error for an unknown organization', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(InspectOrganizationTool::class, ['organization' => 'no-such-org'])
        ->assertHasErrors();
});

it('creates an organization and seats an existing account as owner', function () {
    $owner = mcpMember(mcpOrg('Elsewhere'));

    SapiensServer::actingAs($this->sysadmin)
        ->tool(CreateOrganizationTool::class, [
            'name' => 'New Tenant',
            'owner_email' => $owner->email,
        ])
        ->assertOk()
        ->assertSee('new-tenant');

    $created = Organization::where('slug', 'new-tenant')->first();

    expect($created)->not->toBeNull()
        ->and($created->memberships()->where('user_id', $owner->id)->exists())->toBeTrue();

    expect(PlatformAuditLog::where('action', 'create_organization')->exists())->toBeTrue();
});

it('refuses to seat an owner who has no account yet', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(CreateOrganizationTool::class, [
            'name' => 'Ghost Tenant',
            'owner_email' => 'nobody@example.com',
        ])
        ->assertHasErrors();

    expect(Organization::where('slug', 'ghost-tenant')->exists())->toBeFalse();
});

it('replays an idempotent create instead of making a second organization', function () {
    $arguments = ['name' => 'Once Only', 'idempotency_key' => 'key-123'];

    SapiensServer::actingAs($this->sysadmin)->tool(CreateOrganizationTool::class, $arguments)->assertOk();
    SapiensServer::actingAs($this->sysadmin)->tool(CreateOrganizationTool::class, $arguments)->assertOk();

    expect(Organization::withTrashed()->where('name', 'Once Only')->count())->toBe(1);
});

it('suspends and restores an organization without destroying it', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManageOrganizationTool::class, ['organization' => $this->org->slug, 'action' => 'suspend'])
        ->assertOk()
        ->assertSee('Soft-deleted');

    expect(Organization::withTrashed()->find($this->org->id)->trashed())->toBeTrue()
        ->and(Organization::find($this->org->id))->toBeNull();

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManageOrganizationTool::class, ['organization' => $this->org->slug, 'action' => 'restore'])
        ->assertOk();

    expect(Organization::find($this->org->id))->not->toBeNull();
});

it('renames without touching the slug the URLs depend on', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManageOrganizationTool::class, [
            'organization' => $this->org->id,
            'action' => 'rename',
            'name' => 'Acme Renamed',
        ])
        ->assertOk();

    $this->org->refresh();

    expect($this->org->name)->toBe('Acme Renamed')
        ->and($this->org->slug)->toBe($this->org->getOriginal('slug'));
});

it('sets a platform spend cap and reports the effective limit', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(SetOrganizationBudgetTool::class, [
            'organization' => $this->org->slug,
            'platform_system_cap' => 50,
            'system_monthly_budget' => 200,
            'enforcement_enabled' => true,
        ])
        ->assertOk()
        // The lower of the two wins, so the platform cap takes over.
        ->assertSee('effective_system_limit');

    $budget = OrganizationAiBudget::where('organization_id', $this->org->id)->first();

    expect($budget->platform_system_cap)->toBe(50.0)
        ->and($budget->effectiveLimit('system'))->toBe(50.0)
        ->and($budget->enforcement_enabled)->toBeTrue();
});

it('refuses a budget call that changes nothing', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(SetOrganizationBudgetTool::class, ['organization' => $this->org->slug])
        ->assertHasErrors();
});
