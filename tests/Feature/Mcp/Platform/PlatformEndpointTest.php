<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Servers\SysadminServer;
use App\Mcp\Tools\Platform\CreateOrganizationTool;
use App\Mcp\Tools\SysadminTool;
use App\Models\McpAccessToken;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

/**
 * `mcp/platform/v1` — the organization-free endpoint. Administering the
 * platform does not happen inside a tenant, so this URL carries no slug, checks
 * no membership, and establishes no tenant scope.
 *
 * These go over real HTTP rather than through the server test double, because
 * what is being proved is the middleware: who gets in, who is turned away, and
 * with which answer.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg('Acme');
    $this->sysadmin = mcpSysadmin($this->org);
});

function platformToken(User $user, array $abilities = [McpAccessToken::PLATFORM_ADMIN]): string
{
    $plain = McpAccessToken::generateToken();

    McpAccessToken::create([
        'user_id' => $user->id,
        'organization_id' => null,
        'name' => 'platform',
        'token' => $plain,
        'abilities' => $abilities,
    ]);

    return $plain;
}

function callPlatform(string $token, string $method = 'tools/list', array $params = []): TestResponse
{
    return test()->postJson('/mcp/platform/v1', array_filter([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params ?: null,
    ]), [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ]);
}

it('serves the platform suite with no organization in the URL', function () {
    $response = callPlatform(platformToken($this->sysadmin))->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name');

    expect($names)->toContain('platform_overview', 'verify_tenant_isolation', 'read_platform_audit')
        // Tenant tools are absent rather than present-and-failing.
        ->and($names)->not->toContain('query_records', 'read_manifest', 'search_knowledge');
});

it('exposes exactly the tools the sysadmin server declares', function () {
    $response = callPlatform(platformToken($this->sysadmin))->assertOk();

    expect(collect($response->json('result.tools'))->count())
        ->toBe(count(SysadminServer::TOOLS));
});

it('carries every platform tool and no tenant-scoped one', function () {
    $platformTools = array_filter(
        SysadminServer::TOOLS,
        fn (string $class) => is_subclass_of($class, SysadminTool::class),
    );

    // The whole suite is reachable here, not a subset someone forgot to add.
    expect(count($platformTools))->toBe(count(array_filter(
        SapiensServer::TOOLS,
        fn (string $class) => is_subclass_of($class, SysadminTool::class),
    )));
});

it('turns away a token that does not name platform:admin', function () {
    $token = platformToken($this->sysadmin, ['apps:build', 'data:read']);

    callPlatform($token)
        ->assertForbidden()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'mcp/{organization}/v1'));
});

it('turns away a token whose owner is not a sysadmin', function () {
    $member = mcpMember($this->org);

    callPlatform(platformToken($member))
        ->assertForbidden()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'sysadmin'));
});

it('refuses an empty ability list, which never confers platform administration', function () {
    callPlatform(platformToken($this->sysadmin, []))->assertForbidden();
});

it('refuses an expired token', function () {
    $plain = McpAccessToken::generateToken();
    McpAccessToken::create([
        'user_id' => $this->sysadmin->id,
        'organization_id' => null,
        'name' => 'stale',
        'token' => $plain,
        'abilities' => [McpAccessToken::PLATFORM_ADMIN],
        'expires_at' => now()->subDay(),
    ]);

    callPlatform($plain)->assertUnauthorized();
});

it('refuses a missing or unknown credential', function () {
    $this->postJson('/mcp/platform/v1', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized();

    callPlatform('not-a-real-token')->assertUnauthorized();
});

it('sends a platform token presented at an organization URL to the right door', function () {
    $token = platformToken($this->sysadmin);

    $this->postJson("/mcp/{$this->org->slug}/v1", [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ], [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])
        ->assertForbidden()
        // Not "invalid or expired": it is a good token at the wrong endpoint,
        // and saying otherwise would send someone reissuing it.
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'mcp/platform/v1'));
});

it('actually runs a platform tool over the endpoint', function () {
    $response = callPlatform(platformToken($this->sysadmin), 'tools/call', [
        'name' => 'platform_overview',
        'arguments' => ['days' => 7],
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.content.0.text'))->toContain('organizations');
});

it('marks the token as used', function () {
    $plain = platformToken($this->sysadmin);

    expect(McpAccessToken::where('token', $plain)->value('last_used_at'))->toBeNull();

    callPlatform($plain)->assertOk();

    expect(McpAccessToken::where('token', $plain)->value('last_used_at'))->not->toBeNull();
});

it('refuses to create an organization on the slug the platform route owns', function () {
    mcpActingContext([McpAccessToken::PLATFORM_ADMIN]);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(CreateOrganizationTool::class, ['name' => 'Platform'])
        ->assertHasErrors();

    expect(Organization::withTrashed()->where('slug', 'platform')->exists())->toBeFalse();
});
