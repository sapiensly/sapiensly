<?php

use App\Events\Chat\ChatActionExecuted;
use App\Jobs\Chat\SynthesizeThread;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function proposalFor(Chat $chat): ChatMessage
{
    return ChatMessage::factory()->create([
        'chat_id' => $chat->id,
        'role' => 'assistant',
        'status' => 'complete',
        'message_type' => 'action_proposal',
        'action_payload' => [
            'action_type' => 'manual',
            'action_label' => 'Launch Q3 ads',
            'agreed_by' => ['Finance'],
            'parameters' => ['budget' => '$2000'],
            'rationale' => 'Within budget',
        ],
    ]);
}

it('executes a manual action, records a result, and marks the thread executed', function () {
    Event::fake([ChatActionExecuted::class]);
    $chat = Chat::factory()->forUser($this->user)->create([
        'mode' => 'multi_agent', 'synthesis_status' => 'ready',
    ]);
    $proposal = proposalFor($chat);

    $this->actingAs($this->user)
        ->postJson(route('chat.actions.execute', ['chat' => $chat, 'message' => $proposal]))
        ->assertOk()
        ->assertJsonPath('synthesis_status', 'executed')
        ->assertJsonPath('message.message_type', 'action_result');

    expect(ChatMessage::where('chat_id', $chat->id)->where('message_type', 'action_result')->count())->toBe(1)
        ->and($chat->refresh()->synthesis_status)->toBe('executed');

    Event::assertDispatched(ChatActionExecuted::class);
});

it('refuses to execute the same proposal twice', function () {
    $chat = Chat::factory()->forUser($this->user)->create([
        'mode' => 'multi_agent', 'synthesis_status' => 'executed',
    ]);
    $proposal = proposalFor($chat);

    $this->actingAs($this->user)
        ->postJson(route('chat.actions.execute', ['chat' => $chat, 'message' => $proposal]))
        ->assertStatus(422);
});

it('dismisses a proposal', function () {
    Event::fake([ChatActionExecuted::class]);
    $chat = Chat::factory()->forUser($this->user)->create([
        'mode' => 'multi_agent', 'synthesis_status' => 'ready',
    ]);
    $proposal = proposalFor($chat);

    $this->actingAs($this->user)
        ->deleteJson(route('chat.actions.dismiss', ['chat' => $chat, 'message' => $proposal]))
        ->assertOk()
        ->assertJsonPath('synthesis_status', 'dismissed');

    expect($chat->refresh()->synthesis_status)->toBe('dismissed');
    Event::assertDispatched(ChatActionExecuted::class);
});

it('blocks acting on a chat the user does not own', function () {
    $chat = Chat::factory()->forUser($this->user)->create(['mode' => 'multi_agent']);
    $proposal = proposalFor($chat);
    $other = User::factory()->create();

    $this->actingAs($other)
        ->postJson(route('chat.actions.execute', ['chat' => $chat, 'message' => $proposal]))
        ->assertNotFound();
});

it('manually triggers synthesis', function () {
    Bus::fake();
    $chat = Chat::factory()->forUser($this->user)->create(['mode' => 'multi_agent']);

    $this->actingAs($this->user)
        ->postJson(route('chat.synthesize', $chat))
        ->assertStatus(202)
        ->assertJsonPath('synthesis_status', 'pending');

    Bus::assertDispatched(SynthesizeThread::class);
});
