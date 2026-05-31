<?php

use App\Jobs\RunChatAiJob;
use App\Models\AiProvider;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

it('creates the user message + assistant placeholder and dispatches the job', function () {
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'Hello'])
        ->assertCreated()
        ->assertJsonPath('user_message.role', 'user')
        ->assertJsonPath('placeholder.role', 'assistant')
        ->assertJsonPath('placeholder.status', 'pending');

    expect(ChatMessage::where('chat_id', $chat->id)->where('role', 'user')->count())->toBe(1)
        ->and(ChatMessage::where('chat_id', $chat->id)->where('role', 'assistant')->count())->toBe(1);

    Queue::assertPushed(RunChatAiJob::class);
});

it('links pre-uploaded attachments to the user message', function () {
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();
    $attachment = ChatAttachment::factory()->create([
        'chat_id' => $chat->id,
        'user_id' => $this->user->id,
        'chat_message_id' => null,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), [
            'content' => 'See attached',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertCreated();

    $userMessage = ChatMessage::where('chat_id', $chat->id)->where('role', 'user')->firstOrFail();
    expect($attachment->refresh()->chat_message_id)->toBe($userMessage->id);
});

it('rejects an empty message with no attachments', function () {
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => ''])
        ->assertStatus(422);
});

it('forbids sending to a chat the user does not own', function () {
    $chat = Chat::factory()->forUser($this->user)->create();
    $other = User::factory()->create();

    $this->actingAs($other)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'hi'])
        ->assertForbidden();
});
