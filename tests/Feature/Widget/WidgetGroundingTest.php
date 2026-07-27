<?php

use App\Ai\RuntimeAgent;
use App\Enums\BotFlowStatus;
use App\Enums\ChatbotStatus;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\BotFlow;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\WidgetMessage;
use App\Services\LLMService;
use App\Services\RetrievalService;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;

/**
 * A support bot that says it consulted the manual has to have consulted it.
 *
 * The widget used to retrieve from the agent's knowledge bases, keep the LIST of
 * bases for the "consulted X" chip and the stored metadata, and throw the
 * retrieved TEXT away — then answer from the bare prompt. It paid for the
 * embedding and the vector search, told the visitor their question had been
 * researched, and grounded nothing. A bot with tools attached skipped retrieval
 * altogether.
 *
 * The assertion that matters is not "retrieval ran". It is that the retrieved
 * text reached the model.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    $this->agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'prompt_template' => 'Eres el concierge.',
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

/** Stand in for the vector store with a passage we can look for in the prompt. */
function fakeRetrieval(string $passage, string $baseName = 'Manual'): void
{
    $retrieval = Mockery::mock(RetrievalService::class);
    $retrieval->shouldReceive('retrieve')->andReturn([
        'context' => $passage,
        'knowledge_bases' => [['id' => 'kb_1', 'name' => $baseName]],
        'chunk_count' => 1,
    ]);
    app()->instance(RetrievalService::class, $retrieval);
}

/** Give the agent a real knowledge base so retrieval is attempted at all. */
function attachKnowledgeBase(Agent $agent, Organization $org, User $user): void
{
    $base = KnowledgeBase::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Manual',
    ]);

    $agent->syncKnowledgeBases([$base->id]);
}

function groundedTurn(string $apiToken, string $question): void
{
    $session = test()->postJson('/api/widget/v1/sessions', [], ['Authorization' => "Bearer {$apiToken}"])
        ->json('session_token');
    $headers = ['Authorization' => "Bearer {$apiToken}", 'X-Session-Token' => $session];
    $conversation = test()->postJson('/api/widget/v1/conversations', ['session_token' => $session], $headers)
        ->json('conversation_id');
    test()->postJson("/api/widget/v1/conversations/{$conversation}/messages", ['content' => $question], $headers);
    test()->get("/api/widget/v1/conversations/{$conversation}/stream", $headers)->streamedContent();
}

it('puts the retrieved passage in front of the model, not just in the metadata', function () {
    attachKnowledgeBase($this->agent, $this->org, $this->user);
    fakeRetrieval('El envío tarda 3 días hábiles a todo el país.');
    Ai::fakeAgent(RuntimeAgent::class, ['Tarda 3 días.']);

    groundedTurn($this->token->token, '¿Cuánto tarda el envío?');

    Ai::assertAgentWasPrompted(
        RuntimeAgent::class,
        fn ($prompt) => str_contains($prompt->agent->instructions(), '3 días hábiles'),
    );
});

it('grounds a bot that also has tools, which used to skip retrieval entirely', function () {
    attachKnowledgeBase($this->agent, $this->org, $this->user);
    fakeRetrieval('La garantía cubre 12 meses.');
    Ai::fakeAgent(RuntimeAgent::class, ['Doce meses.']);

    // The old code forked on "does this agent have tools?" and the tool branch
    // never retrieved. One path now serves both.
    groundedTurn($this->token->token, '¿Cuánta garantía tiene?');

    Ai::assertAgentWasPrompted(
        RuntimeAgent::class,
        fn ($prompt) => str_contains($prompt->agent->instructions(), '12 meses'),
    );
});

/**
 * Found by the evaluation harness, not by reading the code: asked its opening
 * hours, a bot with no knowledge base answered "de 9:00 a 17:00" — an hour
 * nobody had ever told it. Every grounding instruction lived inside the branch
 * that only runs when a knowledge base is attached, so the bot with nothing to
 * answer from was the one bot that received none of them.
 */
it('tells a public bot with no knowledge base that it has nothing to answer from', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['Hola.']);

    groundedTurn($this->token->token, '¿Cuál es su horario?');

    Ai::assertAgentWasPrompted(
        RuntimeAgent::class,
        fn ($prompt) => str_contains($prompt->agent->instructions(), 'do not have it on file'),
    );
});

/**
 * The same silence is correct on an internal turn: a general assistant with no
 * knowledge base is not a bot pretending to know a company, and telling it to
 * refuse everything outside its prompt would break it.
 */
it('leaves an internal agent with no knowledge base alone', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['Hola.']);

    $message = new Message(['role' => MessageRole::User, 'content' => '¿Cuál es su horario?']);
    app(LLMService::class)->chatWithKnowledgeAndTools($this->agent, [$message]);

    Ai::assertAgentWasPrompted(
        RuntimeAgent::class,
        fn ($prompt) => ! str_contains($prompt->agent->instructions(), 'No knowledge base is attached'),
    );
});

it('does not claim a knowledge base it never used', function () {
    // No knowledge base attached: nothing retrieved, so nothing may be reported.
    Ai::fakeAgent(RuntimeAgent::class, ['Hola.']);

    groundedTurn($this->token->token, 'Hola');

    $metadata = WidgetMessage::where('role', MessageRole::Assistant)
        ->value('metadata');

    expect($metadata['knowledge_bases'] ?? [])->toBe([]);
});
