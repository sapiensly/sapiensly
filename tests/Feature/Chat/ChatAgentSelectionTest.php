<?php

use App\Ai\ChatAgent;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

it('lists the user standalone agents in the chat shared props', function () {
    Agent::factory()->standalone()->general()->create(['user_id' => $this->user->id, 'name' => 'My Council']);
    Agent::factory()->standalone()->create(); // another user's agent

    $this->actingAs($this->user)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('chat/Index')
            ->has('agents', 1)
            ->where('agents.0.name', 'My Council'));
});

it('selecting an agent in a message sets agent_id + model on the chat', function () {
    Queue::fake();
    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'model' => 'claude-sonnet-4-20250514',
    ]);
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), [
            'content' => 'Hello',
            'model' => "agent:{$agent->id}",
        ])
        ->assertCreated();

    $chat->refresh();
    expect($chat->agent_id)->toBe($agent->id)
        ->and($chat->model)->toBe('claude-sonnet-4-20250514');
});

it('selecting a plain model clears a previously selected agent', function () {
    Queue::fake();
    $agent = Agent::factory()->standalone()->general()->create(['user_id' => $this->user->id]);
    $chat = Chat::factory()->forUser($this->user)->create(['agent_id' => $agent->id]);

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), [
            'content' => 'Hi',
            'model' => 'claude-sonnet-4-20250514',
        ])
        ->assertCreated();

    expect($chat->refresh()->agent_id)->toBeNull();
});

it('ignores an agent that does not belong to the user', function () {
    Queue::fake();
    $foreign = Agent::factory()->standalone()->create();
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), [
            'content' => 'Hi',
            'model' => "agent:{$foreign->id}",
        ])
        ->assertCreated();

    expect($chat->refresh()->agent_id)->toBeNull();
});

it('runs the chat turn with the agent model and prompt when an agent is selected', function () {
    Ai::fakeAgent(ChatAgent::class, ['Answer as the agent.']);

    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'model' => 'claude-opus-4-20250514',
        'prompt_template' => 'You are the council agent.',
    ]);
    $chat = Chat::factory()->forUser($this->user)->create(['agent_id' => $agent->id, 'model' => $agent->model]);
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'Hi', 'status' => 'complete']);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    app(ChatAiService::class)->streamMessage($placeholder, 'Hi', null);

    $placeholder->refresh();
    expect($placeholder->status)->toBe('complete')
        ->and($placeholder->content)->toBe('Answer as the agent.')
        ->and($placeholder->model)->toBe('claude-opus-4-20250514');
});
