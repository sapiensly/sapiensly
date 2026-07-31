<?php

use App\Ai\Tools\Platform\PlatformToolsFactory;
use App\Mcp\McpContext;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Platform\PlatformOverviewTool;
use App\Mcp\Tools\SysadminTool;
use App\Models\McpAccessToken;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The gate in front of the whole platform-administration suite. Two keys must
 * both turn — the token names `platform:admin` AND the caller holds the
 * sysadmin role — and neither alone is enough. Every other test in this
 * directory assumes this one passes.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg();
    $this->sysadmin = mcpSysadmin($this->org);
    $this->owner = mcpMember($this->org);
});

it('lets a sysadmin whose token names platform:admin through', function () {
    mcpActingContext(['platform:admin']);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(PlatformOverviewTool::class, [])
        ->assertOk()
        ->assertSee('organizations');
});

it('hides the suite from an org owner who is not a sysadmin', function () {
    // The ability is on the token — the role is what is missing.
    mcpActingContext(['platform:admin']);

    SapiensServer::actingAs($this->owner)
        ->tool(PlatformOverviewTool::class, [])
        ->assertHasErrors();
});

it('hides the suite from a sysadmin whose token does not name the ability', function () {
    mcpActingContext(['apps:build', 'data:read']);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(PlatformOverviewTool::class, [])
        ->assertHasErrors();
});

it('does NOT grant platform:admin through an empty ability list', function () {
    // An empty list means "every tenant ability" as a convenience. Platform
    // administration must never be handed out by omission.
    mcpActingContext([]);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(PlatformOverviewTool::class, [])
        ->assertHasErrors();

    expect((new McpAccessToken(['abilities' => []]))->hasAbility('apps:build'))->toBeTrue()
        ->and((new McpAccessToken(['abilities' => []]))->hasAbility(McpAccessToken::PLATFORM_ADMIN))->toBeFalse();
});

it('fails closed when no token is bound at all (the OAuth connection)', function () {
    // AuthenticateMcpToken builds an McpContext with a null token for OAuth
    // callers; they carry no ability list, so they must not reach the suite.
    app()->instance(McpContext::class, new McpContext(null));

    SapiensServer::actingAs($this->sysadmin)
        ->tool(PlatformOverviewTool::class, [])
        ->assertHasErrors();
});

it('resolves the sysadmin role even while the permissions team is pinned to an org', function () {
    // This is the trap: spatie scopes roles to the current team, and sysadmin
    // is assigned with a null team. Every MCP request pins an org, so a plain
    // hasRole() check silently returns false.
    setPermissionsTeamId($this->org->id);

    expect($this->sysadmin->hasRole('sysadmin'))->toBeFalse()
        ->and($this->sysadmin->isSysAdmin())->toBeTrue()
        ->and($this->owner->isSysAdmin())->toBeFalse();

    // The team scope the caller had is restored, not left as null.
    expect(getPermissionsTeamId())->toBe($this->org->id);
});

it('never bridges a platform tool into an in-process agent toolset', function () {
    $platformTools = array_values(array_filter(
        SapiensServer::TOOLS,
        fn (string $class) => is_subclass_of($class, SysadminTool::class),
    ));

    expect($platformTools)->not->toBeEmpty();

    $bridged = array_map(
        fn (object $tool) => class_basename($tool),
        PlatformToolsFactory::for($this->sysadmin),
    );

    foreach ($platformTools as $class) {
        $name = (string) str($class)->classBasename()->beforeLast('Tool')->snake();
        expect($bridged)->not->toContain($name);
    }
});
