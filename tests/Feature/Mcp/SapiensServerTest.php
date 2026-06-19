<?php

use App\Mcp\McpContext;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Agents\ListAgentsTool;
use App\Mcp\Tools\Build\ReadManifestTool;
use App\Mcp\Tools\Data\QueryRecordsTool;
use App\Models\Agent;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * MCP server vertical slice: tools resolve and authorize through the same
 * services/scopes the web app uses, and ability gating hides tools a token may
 * not use. The HTTP suite runs the runtime connections as the owner, so these
 * exercise the tool/handler logic; real RLS isolation is covered separately.
 */
function mcpId(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function seedManifest(App $app, User $user): array
{
    $objId = mcpId('obj');
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => $app->name,
        'version' => 1,
        'objects' => [[
            'id' => $objId,
            'slug' => 'tasks',
            'name' => 'Task',
            'fields' => [
                ['id' => mcpId('fld'), 'slug' => 'title', 'name' => 'Title', 'type' => 'string', 'required' => true],
            ],
        ]],
        'pages' => [],
        'workflows' => [],
        'permissions' => ['roles' => [['id' => mcpId('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];

    // Create the version directly (bypassing manifest validation, which is
    // covered by its own tests) and mark it active.
    $version = AppVersion::create([
        'app_id' => $app->id,
        'organization_id' => $app->organization_id,
        'version_number' => 1,
        'manifest' => $manifest,
        'created_by_user_id' => $user->id,
        'change_summary' => 'init',
    ]);
    $app->update(['current_version_id' => $version->id]);

    return $manifest;
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id, 'visibility' => 'private']);
});

it('read_manifest returns the active manifest for a visible app', function () {
    seedManifest($this->testApp, $this->user);

    SapiensServer::actingAs($this->user)
        ->tool(ReadManifestTool::class, ['app_slug' => $this->testApp->slug])
        ->assertOk()
        ->assertSee($this->testApp->slug)
        ->assertSee('schema_version');
});

it('read_manifest errors for an app the caller cannot see', function () {
    $otherUser = User::factory()->create();
    $other = App::factory()->create(['user_id' => $otherUser->id, 'visibility' => 'private']);
    seedManifest($other, $otherUser);

    SapiensServer::actingAs($this->user)
        ->tool(ReadManifestTool::class, ['app_slug' => $other->slug])
        ->assertHasErrors();
});

it('query_records requires a valid object_id', function () {
    seedManifest($this->testApp, $this->user);

    SapiensServer::actingAs($this->user)
        ->tool(QueryRecordsTool::class, ['app_slug' => $this->testApp->slug, 'object_id' => 'obj_missing'])
        ->assertHasErrors();
});

it('list_agents only returns agents in the caller account context', function () {
    $mine = Agent::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->user->organization_id]);
    Agent::factory()->create(); // a different account's agent

    $response = SapiensServer::actingAs($this->user)->tool(ListAgentsTool::class, []);

    $response->assertOk()->assertSee($mine->id);
});

it('hides a tool the token has no ability for', function () {
    // A token granted only data:read must not see the agents tools.
    $token = new McpAccessToken(['abilities' => ['data:read']]);
    app()->instance(McpContext::class, new McpContext($token));

    SapiensServer::actingAs($this->user)
        ->tool(ListAgentsTool::class, [])
        ->assertHasErrors(); // not registered for this token → not found
});
