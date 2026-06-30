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

it('makes a plain model chat aware of the platform build capabilities', function () {
    Ai::fakeAgent(ChatAgent::class, ['Sure.']);

    $chat = Chat::factory()->forUser($this->user)->create();
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    app(ChatAiService::class)->streamMessage($placeholder, 'I need to track my customers somewhere.', null);

    Ai::assertAgentWasPrompted(
        ChatAgent::class,
        fn ($prompt) => str_contains($prompt->agent->instructions(), 'Building on the Sapiensly platform')
            && str_contains($prompt->agent->instructions(), 'create_app'),
    );
});

it('does not inject the platform guidance when a selected agent governs its own persona', function () {
    Ai::fakeAgent(ChatAgent::class, ['As the agent.']);

    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'prompt_template' => 'You are the council agent.',
    ]);
    $chat = Chat::factory()->forUser($this->user)->create(['agent_id' => $agent->id]);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    app(ChatAiService::class)->streamMessage($placeholder, 'Hi', null);

    Ai::assertAgentWasPrompted(
        ChatAgent::class,
        fn ($prompt) => str_contains($prompt->agent->instructions(), 'You are the council agent.')
            && ! str_contains($prompt->agent->instructions(), 'Building on the Sapiensly platform'),
    );
});
