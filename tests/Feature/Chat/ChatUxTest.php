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

it('generates a concise AI title on the first turn', function () {
    // First fake response feeds the streamed reply, the second the title call.
    Ai::fakeAgent(ChatAgent::class, ['Here is the answer.', 'Great Conversation Title']);
    $chat = Chat::factory()->forUser($this->user)->create(['title' => null]);
    ChatMessage::factory()->create(['chat_id' => $chat->id, 'role' => 'user', 'content' => 'hi', 'status' => 'complete']);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    app(ChatAiService::class)->streamMessage($placeholder, 'hi', null);

    expect($chat->refresh()->title)->toBe('Great Conversation Title');
});
