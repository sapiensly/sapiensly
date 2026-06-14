<?php

use App\Ai\ChatAgent;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

it('streams a mentioned agent turn tagged with the agent', function () {
    Ai::fakeAgent(ChatAgent::class, ['As Finance, I recommend shipping.']);

    $chat = Chat::factory()->forUser($this->user)->create(['mode' => 'multi_agent']);
    ChatMessage::factory()->create([
        'chat_id' => $chat->id, 'role' => 'user', 'content' => 'Ship?', 'status' => 'complete',
    ]);
    $agent = Agent::factory()->forUser($this->user)->active()->create(['name' => 'Finance']);
    $placeholder = ChatMessage::factory()->create([
        'chat_id' => $chat->id, 'role' => 'assistant', 'content' => null, 'status' => 'pending',
    ]);

    app(ChatAiService::class)->streamAgentTurn($placeholder, $agent, 'Ship?');

    $placeholder->refresh();
    expect($placeholder->status)->toBe('complete')
        ->and($placeholder->agent_id)->toBe($agent->id)
        ->and($placeholder->message_type)->toBe('text')
        ->and($placeholder->content)->toBe('As Finance, I recommend shipping.');
});
