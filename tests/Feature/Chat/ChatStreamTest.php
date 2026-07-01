<?php

use App\Ai\ChatAgent;
use App\Events\Chat\ChatStreamChunk;
use App\Events\Chat\ChatStreamComplete;
use App\Events\Chat\ChatStreamError;
use App\Jobs\RunChatAiJob;
use App\Models\AppVersion;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
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

it('truncates and keeps the partial reply when the stream exceeds the wall-clock cap', function () {
    // A tiny (sub-millisecond) cap trips on the first event, standing in for a
    // reply that streams past the wall-clock bound. The turn must finalize as a
    // usable (complete) message with a "cut off" note — never run on until
    // retry_after kills it. (0 would DISABLE the cap, hence a tiny positive value.)
    config(['ai.max_stream_seconds' => 0.0001]);
    Event::fake([ChatStreamChunk::class, ChatStreamComplete::class, ChatStreamError::class]);
    Ai::fakeAgent(ChatAgent::class, ['A very long strategy that never finishes in time.']);

    $chat = Chat::factory()->forUser($this->user)->create(['title' => null]);
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'Hi', 'status' => 'complete']);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    app(ChatAiService::class)->streamMessage($placeholder, 'Hi', null);

    $placeholder->refresh();
    expect($placeholder->status)->toBe('complete')
        ->and($placeholder->content)->toContain('cut off because it was taking too long');

    Event::assertDispatched(ChatStreamComplete::class);
    Event::assertNotDispatched(ChatStreamError::class);
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

it('replaces the cryptic timeout exception with a friendly, actionable message', function () {
    Event::fake([ChatStreamError::class]);

    $chat = Chat::factory()->forUser($this->user)->create();
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'streaming']);

    $e = new MaxAttemptsExceededException('App\Jobs\RunChatAiJob has been attempted too many times.');
    (new RunChatAiJob($placeholder->id, 'Build me an app', null))->failed($e);

    $placeholder->refresh();
    expect($placeholder->status)->toBe('error')
        ->and($placeholder->error)->not->toContain('attempted too many times')
        ->and($placeholder->error)->toContain('ran out of time');
});

it('strips inline consult markers before content is fed back to a model', function () {
    $raw = "Voy a consultar al equipo.\n\n[[consult]]\n\nYa tienes las perspectivas.";
    $out = ChatAiService::stripMarkers($raw);

    expect($out)->not->toContain('[[consult]]')
        ->and($out)->toContain('Voy a consultar al equipo.')
        ->and($out)->toContain('Ya tienes las perspectivas.');
});

it('distinguishes an interrupted (killed) runner from a genuine timeout', function () {
    Event::fake([ChatStreamError::class]);

    $chat = Chat::factory()->forUser($this->user)->create();
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'streaming']);

    // A worker killed with no catchable exception (deploy/restart or OOM) → null.
    (new RunChatAiJob($placeholder->id, 'Build me an app', null))->failed(null);

    $placeholder->refresh();
    expect($placeholder->status)->toBe('error')
        ->and($placeholder->error)->toContain('was interrupted')
        ->and($placeholder->error)->not->toContain('ran out of time');
});

it('tells the user when an app was partially built before the turn ran out of time', function () {
    $chat = Chat::factory()->forUser($this->user)->create();
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'streaming']);

    $app = App\Models\App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'name' => 'Content Engine',
    ]);
    AppVersion::factory()->create([
        'app_id' => $app->id,
        'organization_id' => $this->user->organization_id,
        'created_by_user_id' => $this->user->id,
    ]);

    (new RunChatAiJob($placeholder->id, 'Build me an app', null))->failed(new TimeoutExceededException);

    $placeholder->refresh();
    expect($placeholder->error)->toContain('Content Engine');
});

it('localizes the timeout message to the chat owner', function () {
    $spanishUser = User::factory()->create(['locale' => 'es']);
    $chat = Chat::factory()->forUser($spanishUser)->create();
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'streaming']);

    (new RunChatAiJob($placeholder->id, 'Hola', null))->failed(new TimeoutExceededException);

    $placeholder->refresh();
    expect($placeholder->error)->toContain('se le acabó el tiempo');
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
