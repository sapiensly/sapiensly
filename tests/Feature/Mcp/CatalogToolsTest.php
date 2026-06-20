<?php

use App\Enums\MembershipRole;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Account\GetAiSpendTool;
use App\Mcp\Tools\Account\ListTeamMembersTool;
use App\Mcp\Tools\Account\WhoamiTool;
use App\Mcp\Tools\Build\FrameworkReferenceTool;
use App\Mcp\Tools\Build\ListAppsTool;
use App\Mcp\Tools\Build\ListAvailableComponentsTool;
use App\Mcp\Tools\Chatbots\ListChatbotsTool;
use App\Mcp\Tools\Integrations\ListIntegrationsTool;
use App\Models\App;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

/**
 * The expanded tool catalog: every tool registers and lists cleanly, the catalog
 * proxies delegate to the builder's source of truth, and the list tools resolve
 * within the caller's account context.
 */
it('lists tools from every module', function () {
    $org = mcpOrg();
    $plain = mcpToken($org, mcpMember($org));

    // tools/list paginates; the first page proves the server boots with the
    // expanded catalog and there are more pages (nextCursor) behind it.
    $this->withToken($plain)
        ->postJson("/mcp/{$org->slug}/v1", ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()
        ->assertSee('whoami')
        ->assertSee('list_apps')
        ->assertSee('list_available_components')
        ->assertSee('nextCursor');
});

it('every registered tool instantiates with a unique snake_case name', function () {
    $ref = new ReflectionClass(SapiensServer::class);
    $prop = $ref->getProperty('tools');
    $prop->setAccessible(true);
    $classes = $prop->getValue($ref->newInstanceWithoutConstructor());

    $names = collect($classes)->map(fn (string $c) => app($c)->name());

    expect($names->count())->toBeGreaterThan(35)
        ->and($names->unique()->count())->toBe($names->count())
        ->and($names->every(fn ($n) => (bool) preg_match('/^[a-z][a-z0-9_]+$/', $n)))->toBeTrue();
});

it('advertises the Sapiensly icon in serverInfo', function () {
    $server = (new ReflectionClass(SapiensServer::class))->newInstanceWithoutConstructor();
    $icons = $server->resolvedIcons();

    expect($icons)->not->toBeEmpty()
        ->and($icons[0]->src)->toContain('favicon.svg');
});

it('exposes snake_case tool names without the Tool suffix', function () {
    expect((new ListAppsTool)->name())->toBe('list_apps')
        ->and((new ListAvailableComponentsTool)->name())->toBe('list_available_components');
});

it('list_available_components delegates to the builder catalog', function () {
    $user = User::factory()->create();

    SapiensServer::actingAs($user)
        ->tool(ListAvailableComponentsTool::class, [])
        ->assertOk()
        ->assertSee('table');
});

it('framework_reference lists topics when called with no topic', function () {
    $user = User::factory()->create();

    SapiensServer::actingAs($user)
        ->tool(FrameworkReferenceTool::class, [])
        ->assertOk()
        ->assertSee('workflows');
});

it('list_apps returns the caller apps', function () {
    $user = User::factory()->create();
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);
    App::factory()->create(['visibility' => 'private']); // another account's app

    SapiensServer::actingAs($user)
        ->tool(ListAppsTool::class, [])
        ->assertOk()
        ->assertSee($app->slug);
});

it('whoami returns the acting user and the bound organization', function () {
    $org = mcpOrg();
    $user = mcpMember($org, MembershipRole::Owner);

    SapiensServer::actingAs($user)
        ->tool(WhoamiTool::class, [])
        ->assertOk()
        ->assertSee($user->email)
        ->assertSee($org->slug)
        ->assertSee('owner');
});

it('list_team_members lists the org members with roles', function () {
    $org = mcpOrg();
    $owner = mcpMember($org, MembershipRole::Owner);
    $member = mcpMember($org, MembershipRole::Member);

    SapiensServer::actingAs($owner)
        ->tool(ListTeamMembersTool::class, [])
        ->assertOk()
        ->assertSee($owner->email)
        ->assertSee($member->email)
        ->assertSee('member');
});

it('get_ai_spend returns the report for an owner and is denied to a member', function () {
    $org = mcpOrg();
    $owner = mcpMember($org, MembershipRole::Owner);
    $member = mcpMember($org, MembershipRole::Member);

    SapiensServer::actingAs($owner)
        ->tool(GetAiSpendTool::class, ['days' => 30])
        ->assertOk()
        ->assertSee('range_days');

    SapiensServer::actingAs($member)
        ->tool(GetAiSpendTool::class, [])
        ->assertHasErrors();
});

it('list tools return empty cleanly with no data', function () {
    $user = User::factory()->create();

    SapiensServer::actingAs($user)->tool(ListChatbotsTool::class, [])->assertOk();
    SapiensServer::actingAs($user)->tool(ListIntegrationsTool::class, [])->assertOk();
});
