<?php

use App\Ai\RuntimeAgent;
use App\Enums\BotFlowStatus;
use App\Enums\ChatbotStatus;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\BotFlow;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\LLMService;
use App\Support\Ai\AiUsageSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;

/**
 * What a chatbot costs has to be answerable.
 *
 * The ledger already had `module` and `conversation_id`; the channels never
 * filled them, so every widget and WhatsApp turn landed under the generic
 * `agent` module with no conversation. An owner could see the organization's
 * total spend and nothing finer — not what one bot costs, not the cost of a
 * conversation, not cost per resolution, and not that a single bot was being
 * drained. You cannot improve what you cannot attribute.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    $this->agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
    ]);
    $this->chatbot = Chatbot::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'name' => 'Concierge',
        'status' => ChatbotStatus::Active,
        'config' => [],
    ]);
    $this->token = ChatbotApiToken::create([
        'chatbot_id' => $this->chatbot->id,
        'name' => 'T',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat'],
    ]);
    BotFlow::create([
        'chatbot_id' => $this->chatbot->id,
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'name' => 'R',
        'status' => BotFlowStatus::Active,
        'definition' => ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
            ['id' => 'a1', 'type' => 'agent', 'position' => ['x' => 200, 'y' => 0], 'data' => [
                'role' => 'knowledge', 'agent_id' => $this->agent->id, 'agent_name' => $this->agent->name,
            ]],
        ], 'edges' => []],
    ]);
});

function widgetTurn(string $apiToken): string
{
    $session = test()->postJson('/api/widget/v1/sessions', [], ['Authorization' => "Bearer {$apiToken}"])
        ->json('session_token');
    $headers = ['Authorization' => "Bearer {$apiToken}", 'X-Session-Token' => $session];
    $conversation = test()->postJson('/api/widget/v1/conversations', ['session_token' => $session], $headers)
        ->json('conversation_id');
    test()->postJson("/api/widget/v1/conversations/{$conversation}/messages", ['content' => 'hola'], $headers);
    test()->get("/api/widget/v1/conversations/{$conversation}/stream", $headers)->streamedContent();

    return $conversation;
}

it('bills a widget turn to its channel and its conversation', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['ok']);

    $conversation = widgetTurn($this->token->token);

    $event = DB::connection('tenant')->table('ai_usage_events')->latest('id')->first();

    expect($event->module)->toBe('chatbot')
        ->and($event->conversation_id)->toBe($conversation)
        // And to the bot itself, so naming it is a read of the row rather than
        // a join — the agent that answered is an implementation detail.
        ->and($event->subject_type)->toBe('chatbot')
        ->and($event->subject_id)->toBe($this->chatbot->id);
});

/**
 * The point of the conversation id: the chatbot is one join away, so "what does
 * this bot cost" becomes a query instead of a guess.
 */
it('makes per-chatbot cost answerable by joining the conversation', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['ok', 'ok']);

    widgetTurn($this->token->token);
    widgetTurn($this->token->token);

    $turns = DB::connection('tenant')->table('ai_usage_events as e')
        ->join('widget_conversations as c', 'c.id', '=', 'e.conversation_id')
        ->where('c.chatbot_id', $this->chatbot->id)
        ->count();

    expect($turns)->toBe(2);
});

it('leaves a turn with no channel subject labelled as before', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['ok']);

    $message = new Message;
    $message->role = MessageRole::User;
    $message->content = 'hola';

    app(LLMService::class)->setContext($this->user)->chat($this->agent, [$message]);

    $event = DB::connection('tenant')->table('ai_usage_events')->latest('id')->first();

    expect($event->module)->toBe('agent')
        ->and($event->conversation_id)->toBeNull()
        // No channel subject, so the spend bills to the agent that ran it.
        ->and($event->subject_type)->toBe('agent')
        ->and($event->subject_id)->toBe($this->agent->id);
});

/**
 * Scoped by closure and restored in a finally — a queue worker that answers a
 * WhatsApp contact and then runs the owner's own job must not bill the second
 * to the first.
 */
it('lets the subject go when the turn ends', function () {
    $subject = app(AiUsageSubject::class);

    $subject->attributedTo('chatbot', 'conv_1', $this->chatbot, fn () => null);

    expect($subject->module())->toBeNull()
        ->and($subject->conversationId())->toBeNull()
        ->and($subject->artifact())->toBeNull();
});
