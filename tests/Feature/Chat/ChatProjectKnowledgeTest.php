<?php

use App\Ai\ChatAgent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatProject;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use App\Services\RetrievalService;
use Illuminate\Support\Collection;
use Laravel\Ai\Ai;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('attaches only knowledge bases the user can access to a project', function () {
    $mine = KnowledgeBase::factory()->forUser($this->user)->create();
    $foreign = KnowledgeBase::factory()->create(); // someone else's

    $this->actingAs($this->user)
        ->post(route('chat-projects.store'), [
            'name' => 'Research',
            'knowledge_base_ids' => [$mine->id, $foreign->id],
        ])
        ->assertRedirect();

    $project = ChatProject::where('user_id', $this->user->id)->firstOrFail();
    expect($project->knowledgeBases()->pluck('knowledge_bases.id')->all())->toBe([$mine->id]);
});

it('syncs knowledge bases when updating a project', function () {
    $kbA = KnowledgeBase::factory()->forUser($this->user)->create();
    $kbB = KnowledgeBase::factory()->forUser($this->user)->create();
    $project = ChatProject::factory()->create(['user_id' => $this->user->id]);
    $project->knowledgeBases()->attach($kbA->id);

    $this->actingAs($this->user)
        ->patch(route('chat-projects.update', ['chat_project' => $project->id]), [
            'name' => $project->name,
            'knowledge_base_ids' => [$kbB->id],
        ])
        ->assertRedirect();

    expect($project->knowledgeBases()->pluck('knowledge_bases.id')->all())->toBe([$kbB->id]);
});

it('exposes knowledge bases and project KB ids to the chat index', function () {
    $kb = KnowledgeBase::factory()->forUser($this->user)->ready()->create(['name' => 'Docs']);
    $project = ChatProject::factory()->create(['user_id' => $this->user->id]);
    $project->knowledgeBases()->attach($kb->id);

    $this->actingAs($this->user)
        ->get(route('chat.index'))
        ->assertInertia(fn ($page) => $page
            ->has('knowledgeBases', 1)
            ->where('knowledgeBases.0.id', $kb->id)
            ->where('projects.0.knowledge_base_ids.0', $kb->id)
        );
});

it('retrieves project knowledge and injects it for chats in that project', function () {
    $kb = KnowledgeBase::factory()->forUser($this->user)->ready()->create();
    $project = ChatProject::factory()->create(['user_id' => $this->user->id]);
    $project->knowledgeBases()->attach($kb->id);
    $chat = Chat::factory()->forUser($this->user)->create(['chat_project_id' => $project->id]);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    $mock = Mockery::mock(RetrievalService::class);
    $mock->shouldReceive('retrieve')
        ->once()
        ->with('what is our refund policy', [$kb->id], Mockery::any(), Mockery::any())
        ->andReturn([
            'context' => 'Refunds are processed within 14 days.',
            'chunk_count' => 1,
            'knowledge_bases' => [],
            'chunks' => new Collection,
        ]);
    $this->instance(RetrievalService::class, $mock);

    Ai::fakeAgent(ChatAgent::class, ['ok']);

    app(ChatAiService::class)->streamMessage($placeholder, 'what is our refund policy', null);

    // Mockery verifies retrieve() was called with the project's KB id + query.
    expect($placeholder->refresh()->status)->toBe('complete');
});

it('appends retrieved knowledge to the user turn, keeping the system prefix cacheable', function () {
    $kb = KnowledgeBase::factory()->forUser($this->user)->ready()->create();
    $project = ChatProject::factory()->create(['user_id' => $this->user->id]);
    $project->knowledgeBases()->attach($kb->id);
    $chat = Chat::factory()->forUser($this->user)->create(['chat_project_id' => $project->id]);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    $mock = Mockery::mock(RetrievalService::class);
    $mock->shouldReceive('retrieve')->once()->andReturn([
        'context' => 'Refunds are processed within 14 days.',
        'chunk_count' => 1,
        'knowledge_bases' => [],
        'chunks' => new Collection,
    ]);
    $this->instance(RetrievalService::class, $mock);

    // The fake's closure receives the content of the last user message — i.e. the
    // prompt passed to stream(), which now carries the relocated RAG block.
    $capturedPrompt = null;
    Ai::fakeAgent(ChatAgent::class, function ($prompt) use (&$capturedPrompt) {
        $capturedPrompt = $prompt;

        return 'ok';
    });

    app(ChatAiService::class)->streamMessage($placeholder, 'what is our refund policy', null);

    expect($capturedPrompt)->toContain('what is our refund policy')
        ->and($capturedPrompt)->toContain('Refunds are processed within 14 days.');
});

it('does not retrieve when the chat has no project', function () {
    $chat = Chat::factory()->forUser($this->user)->create(['chat_project_id' => null]);
    $placeholder = ChatMessage::factory()->streaming()->create(['chat_id' => $chat->id, 'status' => 'pending']);

    $mock = Mockery::mock(RetrievalService::class);
    $mock->shouldNotReceive('retrieve');
    $this->instance(RetrievalService::class, $mock);

    Ai::fakeAgent(ChatAgent::class, ['ok']);

    app(ChatAiService::class)->streamMessage($placeholder, 'hello', null);

    expect($placeholder->refresh()->status)->toBe('complete');
});
