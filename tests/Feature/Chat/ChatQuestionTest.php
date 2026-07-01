<?php

use App\Ai\ChatAgent;
use App\Ai\Tools\Chat\AskUserQuestionTool;
use App\Events\Chat\ChatQuestionAsked;
use App\Jobs\RunChatAiJob;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\Tools\Request as ToolRequest;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

it('persists a question card and surfaces it live', function () {
    Event::fake([ChatQuestionAsked::class]);
    $chat = Chat::factory()->forUser($this->user)->create();

    $reply = (new AskUserQuestionTool($chat, $this->user))->handle(new ToolRequest([
        'question' => 'Which account should I use?',
        'options' => [
            ['label' => 'Personal', 'description' => 'Your own account'],
            ['label' => 'Business'],
        ],
    ]));

    expect((string) $reply)->toContain('Which account');

    $question = ChatMessage::where('chat_id', $chat->id)
        ->where('message_type', 'question')->sole();

    expect($question->action_payload['question'])->toBe('Which account should I use?')
        ->and($question->action_payload['status'])->toBe('pending')
        ->and($question->action_payload['selected'])->toBeNull()
        // Free-text escape hatch defaults on.
        ->and($question->action_payload['allow_other'])->toBeTrue()
        ->and($question->action_payload['options'])->toHaveCount(2)
        ->and($question->action_payload['options'][0]['label'])->toBe('Personal')
        ->and($question->action_payload['options'][0]['description'])->toBe('Your own account')
        ->and($question->action_payload['options'][1]['description'])->toBeNull();

    Event::assertDispatched(ChatQuestionAsked::class);
});

it('drops blank options and rejects fewer than two choices', function () {
    $chat = Chat::factory()->forUser($this->user)->create();

    $reply = (new AskUserQuestionTool($chat, $this->user))->handle(new ToolRequest([
        'question' => 'Pick one',
        'options' => [['label' => 'Only one'], ['label' => '  ']],
    ]));

    expect((string) $reply)->toStartWith('Error:');
    expect(ChatMessage::where('chat_id', $chat->id)->count())->toBe(0);
});

it('honours allow_other=false', function () {
    $chat = Chat::factory()->forUser($this->user)->create();

    (new AskUserQuestionTool($chat, $this->user))->handle(new ToolRequest([
        'question' => 'A or B?',
        'options' => [['label' => 'A'], ['label' => 'B']],
        'allow_other' => false,
    ]));

    $question = ChatMessage::where('chat_id', $chat->id)->sole();
    expect($question->action_payload['allow_other'])->toBeFalse();
});

it('answering a question locks the card and continues the conversation', function () {
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create(['model' => 'claude-sonnet-5']);
    $question = ChatMessage::factory()->create([
        'chat_id' => $chat->id,
        'role' => 'assistant',
        'status' => 'complete',
        'message_type' => 'question',
        'action_payload' => [
            'question' => 'Which account?',
            'options' => [['label' => 'Personal', 'description' => null], ['label' => 'Business', 'description' => null]],
            'allow_other' => true,
            'selected' => null,
            'status' => 'pending',
        ],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('chat.questions.answer', ['chat' => $chat, 'message' => $question]), [
            'answer' => 'Business',
        ])
        ->assertCreated()
        ->assertJsonPath('question.action_payload.status', 'answered')
        ->assertJsonPath('question.action_payload.selected', 'Business')
        ->assertJsonPath('user_message.content', 'Business')
        ->assertJsonPath('placeholder.status', 'pending');

    expect($question->refresh()->action_payload['status'])->toBe('answered')
        ->and($question->action_payload['selected'])->toBe('Business');

    // The choice runs a normal assistant turn.
    Queue::assertPushed(RunChatAiJob::class, fn (RunChatAiJob $job) => $job->userText === 'Business');
});

it('refuses to answer the same question twice', function () {
    Queue::fake();
    $chat = Chat::factory()->forUser($this->user)->create();
    $question = ChatMessage::factory()->create([
        'chat_id' => $chat->id,
        'role' => 'assistant',
        'status' => 'complete',
        'message_type' => 'question',
        'action_payload' => [
            'question' => 'Which?',
            'options' => [['label' => 'A', 'description' => null], ['label' => 'B', 'description' => null]],
            'allow_other' => true,
            'selected' => 'A',
            'status' => 'answered',
        ],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('chat.questions.answer', ['chat' => $chat, 'message' => $question]), ['answer' => 'B'])
        ->assertStatus(409);

    Queue::assertNothingPushed();
});

it('rejects an empty answer', function () {
    $chat = Chat::factory()->forUser($this->user)->create();
    $question = ChatMessage::factory()->create([
        'chat_id' => $chat->id,
        'role' => 'assistant',
        'status' => 'complete',
        'message_type' => 'question',
        'action_payload' => [
            'question' => 'Which?',
            'options' => [['label' => 'A', 'description' => null], ['label' => 'B', 'description' => null]],
            'allow_other' => true,
            'selected' => null,
            'status' => 'pending',
        ],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('chat.questions.answer', ['chat' => $chat, 'message' => $question]), ['answer' => '  '])
        ->assertStatus(422);
});

it('will not answer a question in someone else\'s chat', function () {
    $other = User::factory()->create();
    $chat = Chat::factory()->forUser($other)->create();
    $question = ChatMessage::factory()->create([
        'chat_id' => $chat->id,
        'role' => 'assistant',
        'status' => 'complete',
        'message_type' => 'question',
        'action_payload' => [
            'question' => 'Which?',
            'options' => [['label' => 'A', 'description' => null], ['label' => 'B', 'description' => null]],
            'allow_other' => true,
            'selected' => null,
            'status' => 'pending',
        ],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('chat.questions.answer', ['chat' => $chat, 'message' => $question]), ['answer' => 'A'])
        ->assertNotFound();
});

it('exposes ask_user_question to a plain chat but not to a selected agent', function () {
    Ai::fakeAgent(ChatAgent::class, ['ok']);

    $chat = Chat::factory()->forUser($this->user)->create();
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);
    app(ChatAiService::class)->streamMessage($placeholder, 'Which one?', null);

    Ai::assertAgentWasPrompted(ChatAgent::class, function ($prompt) {
        $names = collect($prompt->agent->tools())->map(fn ($t) => class_basename($t));

        return $names->contains('ask_user_question');
    });

    Ai::fakeAgent(ChatAgent::class, ['ok']);
    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'prompt_template' => 'You are the council agent.',
    ]);
    $agentChat = Chat::factory()->forUser($this->user)->create(['agent_id' => $agent->id]);
    $agentPlaceholder = ChatMessage::factory()->streaming()->create(['chat_id' => $agentChat->id, 'status' => 'pending']);
    app(ChatAiService::class)->streamMessage($agentPlaceholder, 'Hi', null);

    Ai::assertAgentWasPrompted(ChatAgent::class, function ($prompt) {
        $names = collect($prompt->agent->tools())->map(fn ($t) => class_basename($t));

        return ! $names->contains('ask_user_question');
    });
});
