<?php

use App\Ai\ChatAgent;
use App\Jobs\SummarizeChatHistoryJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create();
});

/**
 * Create $count complete, alternating-role messages with strictly increasing
 * timestamps. $startAt offsets created_at so successive batches stay ordered.
 *
 * @return Collection<int, ChatMessage>
 */
function seedChatHistory(Chat $chat, int $count, int $startAt = 0): Collection
{
    $messages = collect();
    for ($i = 0; $i < $count; $i++) {
        $messages->push(ChatMessage::factory()->create([
            'chat_id' => $chat->id,
            'role' => $i % 2 === 0 ? 'user' : 'assistant',
            'content' => "Message number {$i} content.",
            'status' => 'complete',
            'created_at' => now()->addSeconds($startAt + $i),
        ]));
    }

    return $messages;
}

it('folds older messages into the summary and keeps the recent tail verbatim', function () {
    Ai::fakeAgent(ChatAgent::class, ['CONDENSED SUMMARY']);

    $chat = Chat::factory()->forUser($this->user)->create();
    $messages = seedChatHistory($chat, 20);

    app(ChatAiService::class)->summarizeHistory($chat->fresh());

    $chat->refresh();
    // 20 messages - 10 kept verbatim = 10 folded; watermark is the 10th (index 9).
    expect($chat->summary)->toBe('CONDENSED SUMMARY')
        ->and($chat->summary_through_message_id)->toBe($messages[9]->id);
});

it('does not summarize when too few messages are older than the kept tail', function () {
    Ai::fakeAgent(ChatAgent::class, ['SHOULD NOT RUN']);

    $chat = Chat::factory()->forUser($this->user)->create();
    seedChatHistory($chat, 17); // 17 - 10 = 7 foldable, below the batch minimum of 8

    app(ChatAiService::class)->summarizeHistory($chat->fresh());

    expect($chat->fresh()->summary)->toBeNull();
    Ai::assertAgentNeverPrompted(ChatAgent::class);
});

it('merges the existing summary and advances the watermark on a later run', function () {
    Ai::fakeAgent(
        ChatAgent::class,
        fn ($prompt) => str_contains($prompt, 'Existing summary') ? 'SECOND' : 'FIRST',
    );

    $chat = Chat::factory()->forUser($this->user)->create();
    $first = seedChatHistory($chat, 20);

    app(ChatAiService::class)->summarizeHistory($chat->fresh());
    expect($chat->fresh()->summary)->toBe('FIRST');

    // 10 more messages → 20 again past the watermark → fold the next 10.
    seedChatHistory($chat, 10, 20);
    app(ChatAiService::class)->summarizeHistory($chat->fresh());

    $chat->refresh();
    expect($chat->summary)->toBe('SECOND')
        ->and($chat->summary_through_message_id)->toBe($first[19]->id);

    Ai::assertAgentWasPrompted(
        ChatAgent::class,
        fn ($prompt) => str_contains($prompt->prompt, 'FIRST'),
    );
});

it('queues a summary job after a turn once the conversation is long', function () {
    Bus::fake([SummarizeChatHistoryJob::class]);
    Ai::fakeAgent(ChatAgent::class, ['Reply.']);

    $chat = Chat::factory()->forUser($this->user)->create(['title' => 'Existing']);
    seedChatHistory($chat, 20);
    $placeholder = ChatMessage::factory()->streaming()->create([
        'chat_id' => $chat->id,
        'status' => 'pending',
        'created_at' => now()->addSeconds(100),
    ]);

    app(ChatAiService::class)->streamMessage($placeholder, 'Hi', null);

    Bus::assertDispatched(SummarizeChatHistoryJob::class);
});

it('does not queue a summary job for a short conversation', function () {
    Bus::fake([SummarizeChatHistoryJob::class]);
    Ai::fakeAgent(ChatAgent::class, ['Reply.']);

    $chat = Chat::factory()->forUser($this->user)->create(['title' => 'Existing']);
    seedChatHistory($chat, 3);
    $placeholder = ChatMessage::factory()->streaming()->create([
        'chat_id' => $chat->id,
        'status' => 'pending',
        'created_at' => now()->addSeconds(100),
    ]);

    app(ChatAiService::class)->streamMessage($placeholder, 'Hi', null);

    Bus::assertNotDispatched(SummarizeChatHistoryJob::class);
});
