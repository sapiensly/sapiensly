<?php

use App\Models\McpAccessToken;
use App\Models\PlatformAuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The sysadmin console's MCP screen: where a `platform:admin` credential is
 * issued, and the only place it can be.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg('Acme');
    $this->sysadmin = mcpSysadmin($this->org);
});

it('is reachable only by a sysadmin', function () {
    $member = mcpMember($this->org);

    $this->actingAs($member)->get(route('admin.mcp.index'))->assertForbidden();
    $this->actingAs($this->sysadmin)->get(route('admin.mcp.index'))->assertOk();
});

it('offers every ability, platform administration included', function () {
    $this->actingAs($this->sysadmin)
        ->get(route('admin.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('abilities', McpAccessToken::ABILITIES)
            ->where('platformAbility', McpAccessToken::PLATFORM_ADMIN)
            ->where('organizations.0.slug', $this->org->slug)
        );
});

it('issues a platform token and shows it exactly once', function () {
    $this->actingAs($this->sysadmin)
        ->post(route('admin.mcp.store'), [
            'name' => 'ops laptop',
            'organization_id' => $this->org->id,
            'abilities' => [McpAccessToken::PLATFORM_ADMIN, 'apps:build'],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('plain_token')
        ->assertSessionHas('plain_token_url', url("mcp/{$this->org->slug}/v1"))
        // Its own MCP server name, so adding it cannot overwrite an existing
        // per-organization connection in Claude Code.
        ->assertSessionHas('plain_token_server', 'sapiensly-sysadmin');

    $token = McpAccessToken::where('name', 'ops laptop')->first();

    expect($token->abilities)->toBe([McpAccessToken::PLATFORM_ADMIN, 'apps:build'])
        ->and($token->organization_id)->toBe($this->org->id)
        ->and($token->user_id)->toBe($this->sysadmin->id);

    // Minting a root-equivalent credential is itself an auditable act.
    $entry = PlatformAuditLog::where('action', 'issue_platform_token')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->target_id)->toBe($token->id)
        ->and($entry->channel)->toBe('web');
});

it('does not audit a token that carries no platform ability', function () {
    $this->actingAs($this->sysadmin)
        ->post(route('admin.mcp.store'), [
            'name' => 'tenant only',
            'organization_id' => $this->org->id,
            'abilities' => ['data:read'],
        ])
        ->assertSessionHasNoErrors()
        // A tenant-only token is not a sysadmin connection and must not be
        // named as though it were.
        ->assertSessionHas('plain_token_server', 'sapiensly');

    expect(PlatformAuditLog::where('action', 'issue_platform_token')->exists())->toBeFalse();
});

it('refuses to pin a token to an organization the issuer does not belong to', function () {
    $stranger = mcpOrg('Elsewhere');

    $this->actingAs($this->sysadmin)
        ->post(route('admin.mcp.store'), [
            'name' => 'wrong org',
            'organization_id' => $stranger->id,
            'abilities' => [McpAccessToken::PLATFORM_ADMIN],
        ])
        // The MCP endpoint would reject this token on first use, so the screen
        // refuses to mint it rather than handing over a dead credential.
        ->assertSessionHasErrors('organization_id');

    expect(McpAccessToken::where('name', 'wrong org')->exists())->toBeFalse();
});

it('grants nothing by omission', function () {
    $this->actingAs($this->sysadmin)
        ->post(route('admin.mcp.store'), [
            'name' => 'empty',
            'organization_id' => $this->org->id,
            'abilities' => [],
        ])
        ->assertSessionHasErrors('abilities');

    expect(McpAccessToken::where('name', 'empty')->exists())->toBeFalse();
});

it('lists tokens from every organization and marks the platform ones', function () {
    $other = mcpOrg('Globex');
    $otherOwner = mcpMember($other);

    mcpToken($other, $otherOwner, ['name' => 'their token', 'abilities' => ['data:read']]);
    mcpToken($this->org, $this->sysadmin, ['name' => 'root token', 'abilities' => [McpAccessToken::PLATFORM_ADMIN]]);

    $this->actingAs($this->sysadmin)
        ->get(route('admin.mcp.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $tokens = collect($page->toArray()['props']['tokens']);

            expect($tokens->pluck('name')->all())->toContain('their token', 'root token')
                ->and($tokens->firstWhere('name', 'root token')['isPlatform'])->toBeTrue()
                ->and($tokens->firstWhere('name', 'their token')['isPlatform'])->toBeFalse()
                // The raw value exists nowhere after creation.
                ->and($tokens->firstWhere('name', 'root token'))->not->toHaveKey('token');
        });
});

it('revokes any token on the platform and records it', function () {
    $other = mcpOrg('Globex');
    $otherOwner = mcpMember($other);
    mcpToken($other, $otherOwner, ['name' => 'stale']);
    $stale = McpAccessToken::where('name', 'stale')->first();

    $this->actingAs($this->sysadmin)
        ->delete(route('admin.mcp.destroy', $stale->id))
        ->assertSessionHasNoErrors();

    expect(McpAccessToken::find($stale->id))->toBeNull()
        ->and(PlatformAuditLog::where('action', 'revoke_mcp_token')->exists())->toBeTrue();
});
