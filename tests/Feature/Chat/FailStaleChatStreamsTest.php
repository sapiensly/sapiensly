<?php

use App\Events\Chat\ChatStreamError;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('marks pending and streaming messages older than the cap as errors, keeping partial content', function () {
    $chat = Chat::factory()->forUser($this->user)->create();

    $stale = ChatMessage::factory()->assistant()->create([
        'chat_id' => $chat->id,
        'status' => 'streaming',
        'content' => 'Partial analysis so far…',
        'created_at' => now()->subHours(2),
    ]);
    $stalePending = ChatMessage::factory()->assistant()->create([
        'chat_id' => $chat->id,
        'status' => 'pending',
        'content' => null,
        'created_at' => now()->subHours(2),
    ]);

    $this->artisan('chat:fail-stale-streams')->assertSuccessful();

    $stale->refresh();
    expect($stale->status)->toBe('error')
        ->and($stale->error)->not->toBeEmpty()
        ->and($stale->content)->toBe('Partial analysis so far…');
    expect($stalePending->refresh()->status)->toBe('error');
});

it('broadcasts the error to the open chat UI, not just the database', function () {
    Event::fake([ChatStreamError::class]);
    $chat = Chat::factory()->forUser($this->user)->create();
    $stale = ChatMessage::factory()->assistant()->create([
        'chat_id' => $chat->id,
        'status' => 'streaming',
        'created_at' => now()->subHours(2),
    ]);

    $this->artisan('chat:fail-stale-streams')->assertSuccessful();

    Event::assertDispatched(
        ChatStreamError::class,
        fn ($e) => $e->chatId === $chat->id && $e->messageId === $stale->id && $e->error !== '',
    );
});

it('leaves live streams and finished messages untouched', function () {
    $chat = Chat::factory()->forUser($this->user)->create();

    $live = ChatMessage::factory()->assistant()->create([
        'chat_id' => $chat->id,
        'status' => 'streaming',
        'created_at' => now()->subMinute(),
    ]);
    $done = ChatMessage::factory()->assistant()->create([
        'chat_id' => $chat->id,
        'status' => 'complete',
        'content' => 'All good.',
        'created_at' => now()->subHours(3),
    ]);

    $this->artisan('chat:fail-stale-streams')->assertSuccessful();

    expect($live->refresh()->status)->toBe('streaming');
    expect($done->refresh()->status)->toBe('complete');
});

it('writes the interruption message in the chat owner\'s language', function () {
    $spanishUser = User::factory()->create(['locale' => 'es', 'email_verified_at' => now()]);
    $chat = Chat::factory()->forUser($spanishUser)->create();

    $stale = ChatMessage::factory()->assistant()->create([
        'chat_id' => $chat->id,
        'status' => 'streaming',
        'created_at' => now()->subHours(2),
    ]);

    $this->artisan('chat:fail-stale-streams')->assertSuccessful();

    expect($stale->refresh()->error)->toContain('se interrumpió');
});
