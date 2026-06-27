<?php

use App\Enums\MembershipRole;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Account\GetOrganizationBrandTool;
use App\Mcp\Tools\Account\SetOrganizationBrandTool;
use App\Models\Organization;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Brandbook MCP tools: any member reads the brand (to build on-brand); only
 * an owner/sysadmin sets it. Reuses the same OrganizationBrand normalization as
 * the web Brandbook page.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg();
    $this->owner = mcpMember($this->org, MembershipRole::Owner);
    $this->member = mcpMember($this->org, MembershipRole::Member);
});

it('reads the organization brand', function () {
    $this->org->update(['brand' => ['primary_color' => '#123456', 'font' => 'serif']]);

    SapiensServer::actingAs($this->owner)
        ->tool(GetOrganizationBrandTool::class, [])
        ->assertOk()
        ->assertSee('#123456')
        ->assertSee('serif');
});

it('lets an owner set the brand (partial, normalized)', function () {
    $this->org->update(['brand' => ['primary_color' => '#000000']]);

    SapiensServer::actingAs($this->owner)
        ->tool(SetOrganizationBrandTool::class, ['font' => 'rounded', 'theme' => 'dark'])
        ->assertOk()
        ->assertSee('updated');

    $brand = Organization::find($this->org->id)->brandbook();
    expect($brand->primaryColor)->toBe('#000000')  // untouched key preserved
        ->and($brand->font)->toBe('rounded')        // merged
        ->and($brand->theme)->toBe('dark');
});

it('rejects an invalid colour', function () {
    SapiensServer::actingAs($this->owner)
        ->tool(SetOrganizationBrandTool::class, ['primary_color' => 'blue'])
        ->assertHasErrors();
});

it('forbids a non-admin member from setting the brand', function () {
    SapiensServer::actingAs($this->member)
        ->tool(SetOrganizationBrandTool::class, ['primary_color' => '#abcdef'])
        ->assertHasErrors();

    expect(Organization::find($this->org->id)->brand)->toBeNull();
});
