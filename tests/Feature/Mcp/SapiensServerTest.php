<?php

use App\Enums\AgentStatus;
use App\Mcp\McpContext;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Agents\CreateAgentTool;
use App\Mcp\Tools\Agents\InvokeAgentTool;
use App\Mcp\Tools\Agents\ListAgentModelsTool;
use App\Mcp\Tools\Agents\ListAgentsTool;
use App\Mcp\Tools\Agents\ListConversationsTool;
use App\Mcp\Tools\Agents\UpdateAgentTool;
use App\Mcp\Tools\Build\ReadManifestTool;
use App\Mcp\Tools\Data\QueryRecordsTool;
use App\Models\Agent;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\Conversation;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Services\LLMService;
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

it('invoke_agent starts a conversation and returns its id', function () {
    $agent = Agent::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->user->organization_id]);

    $llm = Mockery::mock(LLMService::class);
    $llm->shouldReceive('setContext')->andReturnSelf();
    $llm->shouldReceive('chat')->once()->andReturn('Hello back!');
    $this->app->instance(LLMService::class, $llm);

    SapiensServer::actingAs($this->user)
        ->tool(InvokeAgentTool::class, ['agent_id' => $agent->id, 'message' => 'Hello'])
        ->assertOk()
        ->assertSee('Hello back!')
        ->assertSee('conversation_id');

    $conversation = Conversation::where('user_id', $this->user->id)->where('agent_id', $agent->id)->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->messages()->count())->toBe(2);
    expect($conversation->title)->toBe('Hello');
});

it('invoke_agent replays prior turns when given a conversation_id', function () {
    $agent = Agent::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->user->organization_id]);
    $conversation = Conversation::create(['user_id' => $this->user->id, 'agent_id' => $agent->id, 'title' => 'prior']);
    $conversation->messages()->create(['role' => 'user', 'content' => 'first question']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => 'first answer']);

    $llm = Mockery::mock(LLMService::class);
    $llm->shouldReceive('setContext')->andReturnSelf();
    $llm->shouldReceive('chat')
        ->once()
        ->withArgs(function ($agentArg, $messages) {
            // Two prior turns + the new user message are handed to the model.
            return count($messages) === 3 && end($messages)->content === 'follow up';
        })
        ->andReturn('second answer');
    $this->app->instance(LLMService::class, $llm);

    SapiensServer::actingAs($this->user)
        ->tool(InvokeAgentTool::class, [
            'agent_id' => $agent->id,
            'message' => 'follow up',
            'conversation_id' => $conversation->id,
        ])
        ->assertOk()
        ->assertSee('second answer');

    expect($conversation->fresh()->messages()->count())->toBe(4);
});

it('list_conversations returns the caller threads for an agent', function () {
    $agent = Agent::factory()->create(['user_id' => $this->user->id, 'organization_id' => $this->user->organization_id]);
    $mine = Conversation::create(['user_id' => $this->user->id, 'agent_id' => $agent->id, 'title' => 'my thread']);

    SapiensServer::actingAs($this->user)
        ->tool(ListConversationsTool::class, ['agent_id' => $agent->id])
        ->assertOk()
        ->assertSee($mine->id)
        ->assertSee('my thread');
});

it('create_agent creates a draft agent in the caller account context', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateAgentTool::class, [
            'type' => 'general',
            'name' => 'My MCP Bot',
            'model' => 'claude-sonnet-4-20250514',
            'description' => 'Built over MCP',
        ])
        ->assertOk()
        ->assertSee('My MCP Bot')
        ->assertSee('draft');

    $agent = Agent::where('user_id', $this->user->id)->where('name', 'My MCP Bot')->first();
    expect($agent)->not->toBeNull();
    expect($agent->status)->toBe(AgentStatus::Draft);
    expect($agent->type->value)->toBe('general');
});

it('update_agent applies a partial update without nulling other fields', function () {
    $agent = Agent::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'name' => 'Original Name',
        'status' => AgentStatus::Draft,
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(UpdateAgentTool::class, [
            'agent_id' => $agent->id,
            'status' => 'active',
        ])
        ->assertOk()
        ->assertSee('active')
        ->assertSee('Original Name');

    $agent->refresh();
    expect($agent->status)->toBe(AgentStatus::Active);
    expect($agent->name)->toBe('Original Name'); // untouched
});

it('update_agent will not touch an agent outside the caller context', function () {
    $other = Agent::factory()->create(); // a different account's agent

    SapiensServer::actingAs($this->user)
        ->tool(UpdateAgentTool::class, ['agent_id' => $other->id, 'name' => 'Hijacked'])
        ->assertHasErrors();

    expect($other->fresh()->name)->not->toBe('Hijacked');
});

it('list_agent_models returns a models array', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ListAgentModelsTool::class, [])
        ->assertOk()
        ->assertSee('models');
});

it('hides a tool the token has no ability for', function () {
    // A token granted only data:read must not see the agents tools.
    $token = new McpAccessToken(['abilities' => ['data:read']]);
    app()->instance(McpContext::class, new McpContext($token));

    SapiensServer::actingAs($this->user)
        ->tool(ListAgentsTool::class, [])
        ->assertHasErrors(); // not registered for this token → not found
});
