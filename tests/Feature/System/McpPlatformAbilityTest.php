<?php

use App\Models\McpAccessToken;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * `platform:admin` is offered on the token screen — and accepted by it — only
 * for a platform sysadmin. Otherwise an organization owner could mint
 * themselves platform administration from inside their own org settings.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg('Acme');
});

it('does not offer the platform ability to an org owner', function () {
    $owner = mcpMember($this->org);

    $this->actingAs($owner)
        ->get(route('system.mcp.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('abilities', [
            'apps:build', 'data:read', 'data:write', 'agents:invoke',
        ]));
});

it('offers it to a sysadmin', function () {
    $sysadmin = mcpSysadmin($this->org);

    $this->actingAs($sysadmin)
        ->get(route('system.mcp.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('abilities', McpAccessToken::ABILITIES));
});

it('rejects an org owner who posts the platform ability anyway', function () {
    $owner = mcpMember($this->org);

    $this->actingAs($owner)
        ->post(route('system.mcp.store'), [
            'name' => 'sneaky',
            'abilities' => ['platform:admin'],
        ])
        ->assertSessionHasErrors('abilities.0');

    expect(McpAccessToken::where('name', 'sneaky')->exists())->toBeFalse();
});

it('lets a sysadmin mint a token carrying it', function () {
    $sysadmin = mcpSysadmin($this->org);

    $this->actingAs($sysadmin)
        ->post(route('system.mcp.store'), [
            'name' => 'platform',
            'abilities' => ['platform:admin'],
        ])
        ->assertSessionHasNoErrors();

    expect(McpAccessToken::where('name', 'platform')->first()->abilities)
        ->toBe(['platform:admin']);
});
