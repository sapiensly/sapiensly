<?php

use App\Jobs\RunChatAiJob;
use App\Models\AiProvider;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

it('renders the chat index with models and the user\'s chats', function () {
    Chat::factory()->forUser($this->user)->create(['title' => 'Mine']);
    Chat::factory()->create(['title' => 'Someone else']);

    $this->actingAs($this->user)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/Index')
            ->has('chats', 1)
            ->where('chats.0.title', 'Mine')
            ->has('models')
            ->where('activeChat', null)
        );
});

it('shows a chat with its messages to the owner', function () {
    $chat = Chat::factory()->forUser($this->user)->create();
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'Hi']);

    $this->actingAs($this->user)
        ->get(route('chat.show', $chat))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeChat.id', $chat->id)
            ->has('activeChat.messages', 1)
        );
});

it('hides another user\'s chat (404)', function () {
    $chat = Chat::factory()->create();

    $this->actingAs($this->user)
        ->get(route('chat.show', $chat))
        ->assertNotFound();
});

it('creates an empty chat and redirects to it', function () {
    $this->actingAs($this->user)
        ->post(route('chat.store'))
        ->assertRedirect();

    expect(Chat::where('user_id', $this->user->id)->count())->toBe(1);
});

it('creates a chat with a first turn and dispatches the job', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post(route('chat.store'), ['content' => 'First message'])
        ->assertRedirect();

    $chat = Chat::where('user_id', $this->user->id)->firstOrFail();
    expect(ChatMessage::where('chat_id', $chat->id)->count())->toBe(2); // user + placeholder
    Queue::assertPushed(RunChatAiJob::class);
});

it('renames and deletes a chat', function () {
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->patch(route('chat.rename', $chat), ['title' => 'Renamed'])
        ->assertRedirect();
    expect($chat->refresh()->title)->toBe('Renamed');

    $this->actingAs($this->user)
        ->delete(route('chat.destroy', $chat))
        ->assertRedirect();
    expect(Chat::find($chat->id))->toBeNull();
});

it('authorizes the private chat broadcast channel for the owner only', function () {
    $chat = Chat::factory()->forUser($this->user)->create();
    $other = User::factory()->create();

    // Invoke the registered channel-authorization closure directly — the null
    // broadcast driver used in tests doesn't run it over /broadcasting/auth.
    $broadcaster = app(Broadcaster::class);
    $channelsProp = (new ReflectionClass($broadcaster))->getProperty('channels');
    $channelsProp->setAccessible(true);
    $callback = $channelsProp->getValue($broadcaster)['chat.conversation.{chatId}'];

    expect($callback($this->user, $chat->id))->toBeTruthy()
        ->and($callback($other, $chat->id))->toBeFalsy();
});
