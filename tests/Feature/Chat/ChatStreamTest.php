<?php

use App\Ai\ChatAgent;
use App\Events\Chat\ChatStreamChunk;
use App\Events\Chat\ChatStreamComplete;
use App\Events\Chat\ChatStreamError;
use App\Jobs\RunChatAiJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('streams a reply into the placeholder and broadcasts completion', function () {
    Event::fake([ChatStreamChunk::class, ChatStreamComplete::class, ChatStreamError::class]);
    Ai::fakeAgent(ChatAgent::class, ['Hello there from the assistant.']);

    $chat = Chat::factory()->forUser($this->user)->create(['title' => null]);
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'Hi', 'status' => 'complete']);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    app(ChatAiService::class)->streamMessage($placeholder, 'Hi', null);

    $placeholder->refresh();
    expect($placeholder->content)->toBe('Hello there from the assistant.')
        ->and($placeholder->status)->toBe('complete');

    $chat->refresh();
    expect($chat->last_message_at)->not->toBeNull()
        ->and($chat->title)->not->toBeNull();

    Event::assertDispatched(ChatStreamChunk::class);
    Event::assertDispatched(ChatStreamComplete::class);
});

it('marks the placeholder errored and broadcasts when the job fails', function () {
    Event::fake([ChatStreamError::class]);

    $chat = Chat::factory()->forUser($this->user)->create();
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'streaming']);

    (new RunChatAiJob($placeholder->id, 'Hi', null))->failed(new RuntimeException('boom'));

    $placeholder->refresh();
    expect($placeholder->status)->toBe('error')
        ->and($placeholder->error)->toContain('boom');

    Event::assertDispatched(ChatStreamError::class);
});

it('sends prior turns in chronological order so the model keeps context', function () {
    // The fake echoes back the prompt it received (the latest user message).
    // With the ordering bug, the oldest message would be sent as the prompt.
    Ai::fakeAgent(ChatAgent::class, fn ($prompt) => "ECHO:{$prompt}");

    $chat = Chat::factory()->forUser($this->user)->create();
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'first question', 'status' => 'complete']);
    ChatMessage::factory()->assistant()->create(['chat_id' => $chat->id, 'content' => 'first answer', 'status' => 'complete']);
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'second question', 'status' => 'complete']);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    app(ChatAiService::class)->streamMessage($placeholder, 'second question', null);

    // The newest user message is the prompt — proves history is ordered newest-last.
    expect($placeholder->refresh()->content)->toBe('ECHO:second question');
});

it('resolves the per-message model override over the chat default', function () {
    Ai::fakeAgent(ChatAgent::class, ['ok']);

    $chat = Chat::factory()->forUser($this->user)->create(['model' => 'claude-haiku-4-5-20251001']);
    $placeholder = ChatMessage::factory()->streaming()->create([
        'chat_id' => $chat->id,
        'status' => 'pending',
        'model' => 'claude-sonnet-4-5',
    ]);

    app(ChatAiService::class)->streamMessage($placeholder, 'Hi', 'claude-opus-4-8');

    // The override wins and is persisted on the message + chat.
    expect($placeholder->refresh()->model)->toBe('claude-opus-4-8')
        ->and($chat->refresh()->model)->toBe('claude-opus-4-8');
});
