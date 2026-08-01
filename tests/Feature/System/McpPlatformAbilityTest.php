<?php

use App\Models\McpAccessToken;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The organization MCP screen grants tenant abilities and nothing else.
 * `platform:admin` is issued from the sysadmin console — not because a sysadmin
 * could not be trusted with the checkbox, but because a credential that
 * administers every organization must not be offered on a screen whose entire
 * frame says "this organization".
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg('Acme');
});

$tenantAbilities = ['apps:build', 'data:read', 'data:write', 'agents:invoke'];

it('offers only the tenant abilities to an org owner', function () use ($tenantAbilities) {
    $owner = mcpMember($this->org);

    $this->actingAs($owner)
        ->get(route('system.mcp.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('abilities', $tenantAbilities));
});

it('does not offer the platform ability even to a sysadmin', function () use ($tenantAbilities) {
    $sysadmin = mcpSysadmin($this->org);

    $this->actingAs($sysadmin)
        ->get(route('system.mcp.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('abilities', $tenantAbilities));
});

it('rejects the platform ability posted to the organization screen', function () {
    foreach ([mcpMember(mcpOrg('Owned')), mcpSysadmin($this->org)] as $actor) {
        $this->actingAs($actor)
            ->post(route('system.mcp.store'), [
                'name' => 'sneaky',
                'abilities' => [McpAccessToken::PLATFORM_ADMIN],
            ])
            ->assertSessionHasErrors('abilities.0');
    }

    expect(McpAccessToken::where('name', 'sneaky')->exists())->toBeFalse();
});
