<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Chatbots\BotFlowReferenceTool;
use App\Mcp\Tools\Chatbots\GetChatbotTool;
use App\Models\Agent;
use App\Models\Chatbot;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('bot_flow_reference returns the full node catalog', function () {
    SapiensServer::actingAs($this->user)
        ->tool(BotFlowReferenceTool::class, [])
        ->assertOk()
        ->assertSee('agent_handoff')
        ->assertSee('human_handoff')
        ->assertSee('input')
        // Documents that target_agent is a role slug, not an account agent id.
        ->assertSee('triage_llm');
});

it('bot_flow_reference can drill into a single node type', function () {
    SapiensServer::actingAs($this->user)
        ->tool(BotFlowReferenceTool::class, ['node_type' => 'input'])
        ->assertOk()
        ->assertSee('variable');
});

it('bot_flow_reference reports an unknown node type', function () {
    SapiensServer::actingAs($this->user)
        ->tool(BotFlowReferenceTool::class, ['node_type' => 'nope'])
        ->assertOk()
        ->assertSee('Unknown node type');
});

it('get_chatbot returns full config and roster for a visible chatbot', function () {
    $agent = Agent::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'type' => 'triage',
    ]);
    $chatbot = Chatbot::factory()->forUser($this->user)->withBotFlow($agent)->create();

    SapiensServer::actingAs($this->user)
        ->tool(GetChatbotTool::class, ['chatbot_id' => $chatbot->id])
        ->assertOk()
        ->assertSee($chatbot->id)
        ->assertSee($agent->id);
});

it('get_chatbot errors for a chatbot the caller cannot see', function () {
    $other = User::factory()->create();
    $chatbot = Chatbot::factory()->forUser($other)->create();

    SapiensServer::actingAs($this->user)
        ->tool(GetChatbotTool::class, ['chatbot_id' => $chatbot->id])
        ->assertHasErrors();
});
