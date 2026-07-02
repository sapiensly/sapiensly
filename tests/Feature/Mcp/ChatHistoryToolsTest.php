<?php

use App\Ai\ChatAgent;
use App\Enums\AgentStatus;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Chats\ContinueChatTool;
use App\Mcp\Tools\Chats\GetChatTool;
use App\Mcp\Tools\Chats\ListChatsTool;
use App\Mcp\Tools\Chats\SearchChatMessagesTool;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Tool;
use App\Models\User;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('list_chats returns the account\'s chats, newest activity first', function () {
    Chat::factory()->forUser($this->user)->create(['title' => 'Refund policy', 'last_message_at' => now()->subDay()]);
    Chat::factory()->forUser($this->user)->create(['title' => 'Onboarding', 'last_message_at' => now()]);

    SapiensServer::actingAs($this->user)
        ->tool(ListChatsTool::class, [])
        ->assertOk()
        ->assertSee('Onboarding')
        ->assertSee('Refund policy');
});

it('list_chats filters by title and date range', function () {
    Chat::factory()->forUser($this->user)->create(['title' => 'Old chat', 'last_message_at' => now()->subDays(40)]);
    Chat::factory()->forUser($this->user)->create(['title' => 'Recent budget chat', 'last_message_at' => now()->subDay()]);

    SapiensServer::actingAs($this->user)
        ->tool(ListChatsTool::class, ['query' => 'budget', 'from' => now()->subWeek()->toDateString()])
        ->assertOk()
        ->assertSee('Recent budget chat')
        ->assertDontSee('Old chat');
});

it('list_chats does not see another account\'s chats', function () {
    $other = User::factory()->create();
    Chat::factory()->forUser($other)->create(['title' => 'Secret strategy']);

    SapiensServer::actingAs($this->user)
        ->tool(ListChatsTool::class, [])
        ->assertOk()
        ->assertDontSee('Secret strategy');
});

it('get_chat returns the full transcript in order', function () {
    $chat = Chat::factory()->forUser($this->user)->create(['title' => 'QA thread']);
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'What is our refund window?']);
    ChatMessage::factory()->assistant()->create(['chat_id' => $chat->id, 'content' => 'Thirty days from purchase.']);

    SapiensServer::actingAs($this->user)
        ->tool(GetChatTool::class, ['chat_id' => $chat->id])
        ->assertOk()
        ->assertSee('refund window')
        ->assertSee('Thirty days from purchase');
});

it('get_chat exposes attached composer tools resolved to name and type, flagging unresolvable ids', function () {
    $tool = Tool::factory()->mcp()->create([
        'user_id' => $this->user->id,
        'name' => 'YuhuGo Metrics',
        'status' => AgentStatus::Active,
    ]);
    $chat = Chat::factory()->forUser($this->user)->create(['tool_ids' => [$tool->id, 'tool_gone']]);

    SapiensServer::actingAs($this->user)
        ->tool(GetChatTool::class, ['chat_id' => $chat->id])
        ->assertOk()
        ->assertSee('YuhuGo Metrics')
        ->assertSee('"type":"mcp"')
        ->assertSee('"tool_id":"tool_gone"')
        ->assertSee('"missing":true');
});

it('get_chat reports the auto tool policy with the effective auto-activated set', function () {
    $tool = Tool::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'rest_api',
        'name' => 'Orders API',
        'status' => AgentStatus::Active,
    ]);
    Tool::factory()->create(['user_id' => $this->user->id, 'type' => 'rest_api', 'status' => AgentStatus::Draft, 'name' => 'Draft API']);
    $chat = Chat::factory()->forUser($this->user)->create(['tool_ids' => null]);

    SapiensServer::actingAs($this->user)
        ->tool(GetChatTool::class, ['chat_id' => $chat->id])
        ->assertOk()
        ->assertSee('"tool_policy":"auto"')
        ->assertSee('Orders API')
        ->assertSee($tool->id)
        ->assertDontSee('Draft API');
});

it('get_chat exposes per-message diagnostics: used sources, consultations, and errors', function () {
    $chat = Chat::factory()->forUser($this->user)->create();
    ChatMessage::factory()->assistant()->create([
        'chat_id' => $chat->id,
        'content' => 'Done.',
        'agent_data_context' => ['Knowledge base' => '3 passages'],
        'consultation_context' => [['agent_name' => 'Analyst', 'question' => 'Q1 numbers?']],
    ]);
    ChatMessage::factory()->assistant()->create([
        'chat_id' => $chat->id,
        'content' => null,
        'status' => 'error',
        'error' => 'The assistant ran out of time.',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(GetChatTool::class, ['chat_id' => $chat->id])
        ->assertOk()
        ->assertSee('3 passages')
        ->assertSee('Analyst')
        ->assertSee('The assistant ran out of time.');
});

it('get_chat rejects an unknown chat', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetChatTool::class, ['chat_id' => 'chat_nope'])
        ->assertHasErrors();
});

it('search_chat_messages finds matching messages with a snippet', function () {
    $chat = Chat::factory()->forUser($this->user)->create(['title' => 'Billing']);
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'Customer asked about a refund yesterday.']);
    ChatMessage::factory()->assistant()->create(['chat_id' => $chat->id, 'content' => 'Unrelated greeting.']);

    SapiensServer::actingAs($this->user)
        ->tool(SearchChatMessagesTool::class, ['query' => 'refund'])
        ->assertOk()
        ->assertSee('refund')
        ->assertSee($chat->id);
});

it('search_chat_messages honours the role filter', function () {
    $chat = Chat::factory()->forUser($this->user)->create();
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'pricing question']);
    ChatMessage::factory()->assistant()->create(['chat_id' => $chat->id, 'content' => 'pricing answer']);

    SapiensServer::actingAs($this->user)
        ->tool(SearchChatMessagesTool::class, ['query' => 'pricing', 'role' => 'assistant'])
        ->assertOk()
        ->assertSee('pricing answer')
        ->assertDontSee('pricing question');
});

it('continue_chat posts a message and returns the assistant reply', function () {
    Ai::fakeAgent(ChatAgent::class, ['Our paid-marketing budget should be about 15% of revenue.']);

    $chat = Chat::factory()->forUser($this->user)->create(['title' => 'Budget', 'model' => 'claude-sonnet-4-20250514']);

    SapiensServer::actingAs($this->user)
        ->tool(ContinueChatTool::class, ['chat_id' => $chat->id, 'message' => 'How much for paid marketing?'])
        ->assertOk()
        ->assertSee('paid-marketing budget');

    expect($chat->messages()->where('role', 'user')->where('content', 'How much for paid marketing?')->exists())->toBeTrue();
    expect($chat->messages()->where('role', 'assistant')->where('status', 'complete')->exists())->toBeTrue();
});
