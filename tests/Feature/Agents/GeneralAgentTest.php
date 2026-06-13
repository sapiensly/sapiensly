<?php

use App\Enums\AgentType;
use App\Enums\MessageRole;
use App\Jobs\ProcessAgentChat;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Models\Tool;
use App\Models\User;
use App\Services\AiProviderService;
use App\Services\LLMService;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates a general agent connected to both knowledge bases and tools', function () {
    $kb = KnowledgeBase::factory()->ready()->create(['user_id' => $this->user->id]);
    $tool = Tool::factory()->function()->active()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->post(route('agents.store'), [
            'type' => AgentType::General->value,
            'name' => 'My General Agent',
            'model' => 'claude-sonnet-4-20250514',
            'prompt_template' => 'You do everything.',
            'knowledge_base_ids' => [$kb->id],
            'tool_ids' => [$tool->id],
            'config' => [
                'rag_params' => ['top_k' => 5, 'similarity_threshold' => 0.7],
                'tool_execution' => ['timeout' => 30000, 'retry_count' => 2],
            ],
        ])
        ->assertRedirect();

    $agent = Agent::where('name', 'My General Agent')->firstOrFail();
    $knowledgeBases = $agent->loadKnowledgeBases();
    expect($agent->type)->toBe(AgentType::General)
        ->and($knowledgeBases)->toHaveCount(1)
        ->and($knowledgeBases->first()->id)->toBe($kb->id)
        ->and($agent->tools)->toHaveCount(1)
        ->and($agent->tools->first()->id)->toBe($tool->id);
});

it('offers the general type on the create form', function () {
    $this->actingAs($this->user)
        ->get(route('agents.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('agentTypes.0.value', 'general')
            ->has('recommendedModels.general'));
});

it('runs a general agent through the unified knowledge + tools path', function () {
    Ai::fakeAgent(AnonymousAgent::class, ['Answer from the general agent.']);

    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'model' => 'claude-sonnet-4-20250514',
        'prompt_template' => 'You do everything.',
    ]);

    $conversation = Conversation::factory()->create([
        'user_id' => $this->user->id,
        'agent_id' => $agent->id,
    ]);
    $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'What is the refund policy?',
    ]);

    (new ProcessAgentChat($agent, $conversation))->handle(
        app(LLMService::class),
        app(AiProviderService::class),
    );

    $assistant = $conversation->messages()->where('role', MessageRole::Assistant)->first();
    expect($assistant)->not->toBeNull()
        ->and($assistant->content)->toBe('Answer from the general agent.');
});

it('returns response and knowledge-base metadata from chatWithKnowledgeAndTools', function () {
    Ai::fakeAgent(AnonymousAgent::class, ['ok']);

    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'model' => 'claude-sonnet-4-20250514',
    ]);
    $message = new Message([
        'role' => MessageRole::User,
        'content' => 'Hello',
    ]);

    $result = app(LLMService::class)->chatWithKnowledgeAndTools($agent, [$message]);

    expect($result)->toHaveKeys(['response', 'knowledge_bases', 'chunk_count'])
        ->and($result['response']->text)->toBe('ok')
        ->and($result['knowledge_bases'])->toBe([]);
});
