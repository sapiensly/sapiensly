<?php

use App\Ai\ChatAgent;
use App\Jobs\RunChatAiJob;
use App\Models\AiProvider;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

// ----- Server-side stop -----

it('flags an in-flight message to stop', function () {
    $chat = Chat::factory()->forUser($this->user)->create();
    $msg = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'streaming']);

    $this->actingAs($this->user)
        ->postJson(route('chat.stop', $chat), ['message_id' => $msg->id])
        ->assertOk()
        ->assertJson(['stopped' => true]);

    expect(Cache::get(ChatAiService::STOP_CACHE_PREFIX.$msg->id))->toBeTrue();
});

it('forbids stopping a chat you do not own', function () {
    $chat = Chat::factory()->forUser($this->user)->create();
    $other = User::factory()->create();

    $this->actingAs($other)
        ->postJson(route('chat.stop', $chat), ['message_id' => 'whatever'])
        ->assertNotFound();
});

it('stops the stream when the flag is set before generation', function () {
    Ai::fakeAgent(ChatAgent::class, ['this should be cut short by the stop flag']);
    $chat = Chat::factory()->forUser($this->user)->create();
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    Cache::put(ChatAiService::STOP_CACHE_PREFIX.$placeholder->id, true, now()->addMinutes(10));

    app(ChatAiService::class)->streamMessage($placeholder, 'hello', null);

    // Worker finalized the (empty) partial reply and cleared the flag.
    expect($placeholder->refresh()->status)->toBe('complete')
        ->and(Cache::get(ChatAiService::STOP_CACHE_PREFIX.$placeholder->id))->toBeNull();
});

// ----- Web search -----

it('passes the web_search flag to the streaming job', function () {
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'latest AI news', 'web_search' => true])
        ->assertCreated();

    Queue::assertPushed(RunChatAiJob::class, fn (RunChatAiJob $job) => $job->webSearch === true);
});

it('defaults web_search to false', function () {
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), ['content' => 'hi'])
        ->assertCreated();

    Queue::assertPushed(RunChatAiJob::class, fn (RunChatAiJob $job) => $job->webSearch === false);
});

// ----- AI-generated titles -----

it('uses a short first message as the title verbatim, without calling the model', function () {
    Ai::fakeAgent(ChatAgent::class, ['Here is the answer.']);
    $chat = Chat::factory()->forUser($this->user)->create(['title' => null]);
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'Reset my password', 'status' => 'complete']);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    app(ChatAiService::class)->streamMessage($placeholder, 'Reset my password', null);

    expect($chat->refresh()->title)->toBe('Reset my password');
    Ai::assertAgentNotPrompted(ChatAgent::class, fn ($p) => str_contains($p->prompt, 'Title for this conversation'));
});

it('generates a title via the model for a long first message', function () {
    // First fake response feeds the streamed reply, the second the title call.
    Ai::fakeAgent(ChatAgent::class, ['Here is the answer.', 'Great Conversation Title']);
    $long = 'Please help me understand exactly how the multi-tenant row level security works in this project.';
    $chat = Chat::factory()->forUser($this->user)->create(['title' => null]);
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => $long, 'status' => 'complete']);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    app(ChatAiService::class)->streamMessage($placeholder, $long, null);

    expect($chat->refresh()->title)->toBe('Great Conversation Title');
});

it('regenerates the title from context once the conversation reaches 6 messages', function () {
    $chat = Chat::factory()->forUser($this->user)->create(['title' => 'Old title']);
    // 2 prior complete turns (4 messages), then a 3rd user message — the placeholder makes 6.
    foreach (range(1, 2) as $i) {
        ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => "u{$i}", 'status' => 'complete', 'created_at' => now()->addSeconds($i * 2)]);
        ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'assistant', 'content' => "a{$i}", 'status' => 'complete', 'created_at' => now()->addSeconds($i * 2 + 1)]);
    }
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'u3', 'status' => 'complete', 'created_at' => now()->addSeconds(100)]);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending', 'created_at' => now()->addSeconds(101)]);

    Ai::fakeAgent(ChatAgent::class, ['Reply.', 'Refined Title']);
    app(ChatAiService::class)->streamMessage($placeholder, 'u3', null);

    expect($chat->refresh()->title)->toBe('Refined Title');
    Ai::assertAgentWasPrompted(ChatAgent::class, fn ($p) => str_contains($p->prompt, 'Generate a concise title for this conversation'));
});
