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
use App\Support\Ai\PublicTurnContext;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;

/**
 * The blast radius of a public channel.
 *
 * LLMService merges the platform tool catalogue into every agent turn, scoped to
 * the AGENT'S OWNER. That is correct when the owner is the one typing. On the
 * widget and on WhatsApp the one typing is a stranger off the internet, and the
 * inheritance handed them ~84 tools over a chat window: reads of the tenant's
 * records, documents, other people's chats, the team roster and the AI spend —
 * and writes, including update_record, update_chatbot, propose_change and
 * invoke_agent. Nothing stood between a visitor and those but the bot's system
 * prompt.
 *
 * These tests pin the boundary: a public turn carries the tools its owner
 * deliberately attached, and nothing else.
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

/** Names of the tools the turn was actually armed with. */
function promptedToolNames(): array
{
    $names = [];
    Ai::assertAgentWasPrompted(RuntimeAgent::class, function ($prompt) use (&$names) {
        foreach ($prompt->agent->tools ?? [] as $tool) {
            $names[] = method_exists($tool, 'name') ? $tool->name() : class_basename($tool);
        }

        return true;
    });

    return $names;
}

/** A plain user message for a turn the owner drives directly. */
function ownerMessage(): Message
{
    $message = new Message;
    $message->role = MessageRole::User;
    $message->content = 'hola';

    return $message;
}

/** Drive one visitor turn through the public widget API. */
function visitorTurn(string $apiToken, string $text = 'hola'): void
{
    $session = test()->postJson('/api/widget/v1/sessions', [], ['Authorization' => "Bearer {$apiToken}"])
        ->json('session_token');
    $conversation = test()->postJson('/api/widget/v1/conversations', ['session_token' => $session], [
        'Authorization' => "Bearer {$apiToken}",
    ])->json('conversation_id');
    $headers = ['Authorization' => "Bearer {$apiToken}", 'X-Session-Token' => $session];
    test()->postJson("/api/widget/v1/conversations/{$conversation}/messages", ['content' => $text], $headers);
    test()->get("/api/widget/v1/conversations/{$conversation}/stream", $headers)->streamedContent();
}

/**
 * The visitor's turn carries exactly one tool, and this is the list — asserted
 * whole rather than by absence, so anything that joins it has to come through
 * this test first.
 */
it('hands a widget visitor one tool and nothing else', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['ok']);

    visitorTurn($this->token->token);

    expect(promptedToolNames())->toBe(['leave_message_for_the_team']);
});

/**
 * Named explicitly rather than left to the count: these are the ones that read
 * or write the tenant, and a regression that re-adds the catalogue would put
 * every one of them back in a stranger's hands.
 */
it('keeps the tenant reads and writes specifically out of reach', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['ok']);

    visitorTurn($this->token->token);
    $names = promptedToolNames();

    foreach ([
        'query_records', 'get_record', 'aggregate_records', 'analyze_app_data',
        'search_knowledge', 'list_documents', 'get_document',
        'list_chats', 'get_chat', 'search_chat_messages', 'list_conversations',
        'list_team_members', 'get_ai_spend', 'whoami',
        'create_record', 'update_record', 'update_chatbot', 'update_agent',
        'propose_change', 'invoke_agent', 'use_tool', 'build_express_dashboard',
    ] as $forbidden) {
        expect($names)->not->toContain($forbidden);
    }
});

it('still runs the tools the owner deliberately attached to the bot', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['ok']);

    // The distinction that matters: an inherited catalogue nobody asked for is
    // withheld, but a tool someone chose to wire to this bot is its job.
    visitorTurn($this->token->token);

    // With no attached tools the turn carries only the one the visitor is
    // entitled to: the way to leave a message for a person. That is the floor,
    // not a bug — the agent answers from its prompt and knowledge alone.
    expect(promptedToolNames())->toBe(['leave_message_for_the_team']);
});

/**
 * A multi-agent bot answers through TeamOrchestrationService, which holds its
 * OWN LLMService. The first version of this guard was a flag on the widget's
 * instance, and that turn walked straight past it still carrying every tool —
 * so the guard moved to a request-scoped context. This is the test that would
 * have caught it.
 */
it('binds the boundary to the turn, not to one service instance', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['ok']);

    // A DIFFERENT LLMService, resolved fresh — this is what
    // TeamOrchestrationService holds, and a multi-agent bot answers through it.
    $orchestratorsOwn = app()->make(LLMService::class);
    expect($orchestratorsOwn)->not->toBe(app()->make(LLMService::class));

    app(PublicTurnContext::class)->runPublic(
        fn () => $orchestratorsOwn->setContext($this->user)->chat($this->agent, [ownerMessage()]),
    );

    expect(promptedToolNames())->toBe([]);
});

it('lets the boundary go when the turn ends, so the next one is not degraded', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['ok']);

    // Restored in a finally: a queue worker that answers a WhatsApp contact and
    // then runs the owner's own job must not carry the restriction over.
    app(PublicTurnContext::class)->runPublic(fn () => null);

    app(LLMService::class)->setContext($this->user)->chat($this->agent, [ownerMessage()]);

    expect(promptedToolNames())->toContain('query_records');
});

it('leaves an owner-driven turn untouched', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['ok']);

    // The same agent, invoked the way its owner invokes it, keeps the catalogue.
    // The boundary is WHO is typing, not which agent it is.
    app(LLMService::class)->setContext($this->user)->chat($this->agent, [ownerMessage()]);

    expect(promptedToolNames())->toContain('query_records');
});
