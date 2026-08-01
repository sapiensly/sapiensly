<?php

use App\Models\McpAccessToken;
use App\Models\PlatformAuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The sysadmin console's MCP screen. A token issued here is GLOBAL — no
 * organization, no ability choice — which is the whole point: it is the
 * credential for administering the platform, and every degree of freedom that
 * could make it something else has been removed.
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

it('advertises the organization-free endpoint and its own server name', function () {
    $this->actingAs($this->sysadmin)
        ->get(route('admin.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('serverUrl', url('mcp/platform/v1'))
            // Its own name, so adding it cannot overwrite an existing
            // per-organization connection in Claude Code.
            ->where('serverName', 'sapiensly-sysadmin')
            // Nothing to choose: no organizations, no ability list.
            ->missing('organizations')
            ->missing('abilities')
        );
});

it('issues a global token carrying platform:admin and nothing else', function () {
    $this->actingAs($this->sysadmin)
        ->post(route('admin.mcp.store'), ['name' => 'ops laptop'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('plain_token');

    $token = McpAccessToken::where('name', 'ops laptop')->first();

    expect($token->abilities)->toBe([McpAccessToken::PLATFORM_ADMIN])
        // Null on purpose — the credential is not bound to a tenant.
        ->and($token->organization_id)->toBeNull()
        ->and($token->user_id)->toBe($this->sysadmin->id);
});

it('ignores an organization or abilities posted alongside the name', function () {
    $this->actingAs($this->sysadmin)
        ->post(route('admin.mcp.store'), [
            'name' => 'smuggled',
            'organization_id' => $this->org->id,
            'abilities' => ['data:write'],
        ])
        ->assertSessionHasNoErrors();

    $token = McpAccessToken::where('name', 'smuggled')->first();

    expect($token->abilities)->toBe([McpAccessToken::PLATFORM_ADMIN])
        ->and($token->organization_id)->toBeNull();
});

it('records every issuance, since minting a root credential is an event', function () {
    $this->actingAs($this->sysadmin)
        ->post(route('admin.mcp.store'), ['name' => 'audited'])
        ->assertSessionHasNoErrors();

    $entry = PlatformAuditLog::where('action', 'issue_platform_token')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_user_id)->toBe($this->sysadmin->id)
        ->and($entry->channel)->toBe('web')
        ->and($entry->target_label)->toBe('audited');
});

it('requires a name', function () {
    $this->actingAs($this->sysadmin)
        ->post(route('admin.mcp.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect(McpAccessToken::count())->toBe(0);
});

it('lists tokens from every organization and marks the platform ones', function () {
    $other = mcpOrg('Globex');
    $otherOwner = mcpMember($other);

    mcpToken($other, $otherOwner, ['name' => 'their token', 'abilities' => ['data:read']]);

    $this->actingAs($this->sysadmin)
        ->post(route('admin.mcp.store'), ['name' => 'root token']);

    $this->actingAs($this->sysadmin)
        ->get(route('admin.mcp.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $tokens = collect($page->toArray()['props']['tokens']);

            expect($tokens->pluck('name')->all())->toContain('their token', 'root token')
                ->and($tokens->firstWhere('name', 'root token')['isPlatform'])->toBeTrue()
                ->and($tokens->firstWhere('name', 'root token')['organization'])->toBeNull()
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
