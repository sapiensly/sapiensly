<?php

use App\Jobs\Chat\InvokeAgentResponse;
use App\Jobs\Chat\SynthesizeThread;
use App\Jobs\RunChatAiJob;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

function mentionAgent(User $user, string $name): Agent
{
    return Agent::factory()->forUser($user)->active()->create(['name' => $name]);
}

it('flips the chat to multi-agent, rosters agents, and chains turns + synthesis', function () {
    Bus::fake();
    $chat = Chat::factory()->forUser($this->user)->create();
    $a = mentionAgent($this->user, 'Finance');
    $b = mentionAgent($this->user, 'Sales');

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), [
            'content' => '@Finance @Sales should we ship Q3?',
            'mentioned_agent_ids' => [$a->id, $b->id],
        ])
        ->assertCreated()
        ->assertJsonPath('mode', 'multi_agent');

    $chat->refresh();
    expect($chat->mode)->toBe('multi_agent')
        ->and($chat->synthesis_status)->toBe('pending')
        ->and(ChatParticipant::where('chat_id', $chat->id)->count())->toBe(2);

    Bus::assertChained([
        InvokeAgentResponse::class,
        InvokeAgentResponse::class,
        SynthesizeThread::class,
    ]);
    Bus::assertNotDispatched(RunChatAiJob::class);
});

it('leaves the single-agent / plain path untouched when there are no mentions', function () {
    Bus::fake();
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'Hello'])
        ->assertCreated()
        ->assertJsonPath('placeholder.role', 'assistant');

    expect($chat->refresh()->mode)->toBe('single');
    Bus::assertDispatched(RunChatAiJob::class);
    Bus::assertNotDispatched(InvokeAgentResponse::class);
});

it('caps the roster at five and adds a system notice', function () {
    Bus::fake();
    $chat = Chat::factory()->forUser($this->user)->create();
    $ids = collect(range(1, 6))
        ->map(fn ($i) => mentionAgent($this->user, "Agent {$i}")->id)
        ->all();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), [
            'content' => 'all hands',
            'mentioned_agent_ids' => $ids,
        ])
        ->assertCreated()
        ->assertJsonPath('system_notice.role', 'system');

    expect(ChatParticipant::where('chat_id', $chat->id)->count())->toBe(5)
        ->and(ChatMessage::where('chat_id', $chat->id)->where('role', 'system')->count())->toBe(1);

    Bus::assertChained([
        InvokeAgentResponse::class,
        InvokeAgentResponse::class,
        InvokeAgentResponse::class,
        InvokeAgentResponse::class,
        InvokeAgentResponse::class,
        SynthesizeThread::class,
    ]);
});

it('rate-limits agent invocations per conversation', function () {
    Bus::fake();
    $chat = Chat::factory()->forUser($this->user)->create();
    $a = mentionAgent($this->user, 'Finance');

    $key = 'chat-agents:u'.$this->user->id.':'.$chat->id;
    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit($key, 60);
    }

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), [
            'content' => '@Finance again',
            'mentioned_agent_ids' => [$a->id],
        ])
        ->assertStatus(429);

    Bus::assertNothingDispatched();
});
