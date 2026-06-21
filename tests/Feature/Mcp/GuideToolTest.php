<?php

use App\Mcp\McpContext;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Account\GuideTool;
use App\Models\McpAccessToken;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('guide with no topic returns the map and the playbook index', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GuideTool::class, [])
        ->assertOk()
        ->assertSee('what_is_sapiensly')
        ->assertSee('agentic-AI platform')
        ->assertSee('abilities')
        ->assertSee('support_squad');
});

it('guide returns the full steps for a topic', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GuideTool::class, ['topic' => 'support_squad'])
        ->assertOk()
        ->assertSee('create_knowledge_base')
        ->assertSee('update_chatbot');
});

it('guide reports an unknown topic with the valid topics', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GuideTool::class, ['topic' => 'nope'])
        ->assertOk()
        ->assertSee('Unknown topic')
        ->assertSee('build_app');
});

it('guide is available even to a token with no abilities', function () {
    // Orientation must never be gated — a bare token still sees it.
    $token = new McpAccessToken(['abilities' => []]);
    app()->instance(McpContext::class, new McpContext($token));

    SapiensServer::actingAs($this->user)
        ->tool(GuideTool::class, [])
        ->assertOk();
});
