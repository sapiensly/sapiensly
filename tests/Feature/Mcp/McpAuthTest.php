<?php

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Passport\Passport;
use Spatie\Permission\PermissionRegistrar;

/**
 * The MCP endpoint is organization-bound: the {organization} in the URL pins the
 * tenant. A personal token must be issued for that org; an OAuth user must be a
 * member of it; either way the org — not the user's active-org pointer — is what
 * the request runs in. mcpOrg/mcpMember/mcpToken/mcpToolsList live in tests/Pest.php.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

it('rejects an unknown organization in the URL', function () {
    $org = mcpOrg();
    $plain = mcpToken($org, mcpMember($org));

    $this->withToken($plain)->postJson('/mcp/nope-nope/v1', mcpToolsList())->assertStatus(401);
});

it('rejects a request with no bearer token', function () {
    $org = mcpOrg();

    $this->postJson("/mcp/{$org->slug}/v1", mcpToolsList())->assertStatus(401);
});

it('rejects an invalid or expired token', function () {
    $org = mcpOrg();
    $user = mcpMember($org);

    $this->withToken('not-a-real-token')->postJson("/mcp/{$org->slug}/v1", mcpToolsList())->assertStatus(401);

    $expired = mcpToken($org, $user, ['expires_at' => now()->subDay()]);
    $this->withToken($expired)->postJson("/mcp/{$org->slug}/v1", mcpToolsList())->assertStatus(401);
});

it('authenticates an org-bound token and binds the tenant scope to that org', function () {
    $org = mcpOrg();
    $user = mcpMember($org);
    $plain = mcpToken($org, $user);

    $this->withToken($plain)->postJson("/mcp/{$org->slug}/v1", mcpToolsList())->assertOk();

    expect(app(TenantContext::class)->organizationId())->toBe($org->id)
        ->and(app(TenantContext::class)->userId())->toBe($user->id);
});

it('rejects a token used on a different organization URL', function () {
    $orgA = mcpOrg('Alpha');
    $orgB = mcpOrg('Beta');
    $user = mcpMember($orgA);
    $plain = mcpToken($orgA, $user); // bound to A

    $this->withToken($plain)->postJson("/mcp/{$orgB->slug}/v1", mcpToolsList())->assertStatus(401);
});

it('pins the org even when the user active org differs', function () {
    $orgA = mcpOrg('Alpha');
    $orgB = mcpOrg('Beta');
    // user is a member of A, but their active org pointer is B.
    $user = mcpMember($orgA, activeOrg: $orgB);
    $plain = mcpToken($orgA, $user);

    $this->withToken($plain)->postJson("/mcp/{$orgA->slug}/v1", mcpToolsList())->assertOk();

    expect(app(TenantContext::class)->organizationId())->toBe($orgA->id);
});

it('authenticates an OAuth member and rejects a non-member', function () {
    $org = mcpOrg();
    $member = mcpMember($org);
    $stranger = User::factory()->create();

    Passport::actingAs($member, ['mcp:use']);
    $this->withToken('oauth-token')->postJson("/mcp/{$org->slug}/v1", mcpToolsList())->assertOk();

    Passport::actingAs($stranger, ['mcp:use']);
    $this->withToken('oauth-token')->postJson("/mcp/{$org->slug}/v1", mcpToolsList())->assertStatus(403);
});
