<?php

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Models\Agent;
use App\Models\KnowledgeBase;
use App\Models\Tool;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('index', function () {
    it('displays the standalone agents index page', function () {
        $this->actingAs($this->user)
            ->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('standalone-agents/Index'));
    });

    it('shows only standalone agents belonging to the authenticated user', function () {
        $myAgent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);
        $otherAgent = Agent::factory()->standalone()->create();

        $this->actingAs($this->user)
            ->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('standalone-agents/Index')
                ->has('agents.data', 1)
                ->where('agents.data.0.id', $myAgent->id)
            );
    });

    it('filters agents by type', function () {
        Agent::factory()->standalone()->triage()->create(['user_id' => $this->user->id]);
        Agent::factory()->standalone()->knowledge()->create(['user_id' => $this->user->id]);
        Agent::factory()->standalone()->action()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('agents.index', ['type' => 'triage']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('agents.data', 1)
                ->where('agents.data.0.type', 'triage')
            );
    });

    it('returns agent counts by type', function () {
        Agent::factory()->standalone()->triage()->count(2)->create(['user_id' => $this->user->id]);
        Agent::factory()->standalone()->knowledge()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('agentsByType.triage', 2)
                ->where('agentsByType.knowledge', 1)
            );
    });
});

describe('create', function () {
    it('displays the create agent form', function () {
        $this->actingAs($this->user)
            ->get(route('agents.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('standalone-agents/Create')
                ->has('agentTypes', 3)
                ->has('availableModels')
                ->has('recommendedModels')
            );
    });

    it('accepts type query parameter', function () {
        $this->actingAs($this->user)
            ->get(route('agents.create', ['type' => 'knowledge']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selectedType', 'knowledge')
            );
    });
});

describe('store', function () {
    it('creates a standalone triage agent', function () {
        $data = [
            'type' => AgentType::Triage->value,
            'name' => 'My Triage Agent',
            'description' => 'Handles routing',
            'model' => 'claude-3-5-haiku-20241022',
            'prompt_template' => 'You are a triage agent.',
            'config' => [
                'temperature' => 0.3,
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('agents.store'), $data)
            ->assertRedirect();

        $this->assertDatabaseHas('agents', [
            'user_id' => $this->user->id,
            'agent_team_id' => null,
            'name' => 'My Triage Agent',
            'type' => AgentType::Triage->value,
            'status' => AgentStatus::Draft->value,
        ]);
    });

    it('creates a knowledge agent with knowledge bases', function () {
        $kb = KnowledgeBase::factory()->ready()->create(['user_id' => $this->user->id]);

        $data = [
            'type' => AgentType::Knowledge->value,
            'name' => 'My Knowledge Agent',
            'model' => 'claude-sonnet-4-20250514',
            'knowledge_base_ids' => [$kb->id],
            'config' => [
                'rag_params' => [
                    'top_k' => 5,
                    'similarity_threshold' => 0.7,
                ],
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('agents.store'), $data)
            ->assertRedirect();

        $agent = Agent::where('name', 'My Knowledge Agent')->first();
        expect($agent->knowledgeBases)->toHaveCount(1);
        expect($agent->knowledgeBases->first()->id)->toBe($kb->id);
    });

    it('creates an action agent with tools', function () {
        $tool = Tool::factory()->function()->active()->create(['user_id' => $this->user->id]);

        $data = [
            'type' => AgentType::Action->value,
            'name' => 'My Action Agent',
            'model' => 'claude-sonnet-4-20250514',
            'tool_ids' => [$tool->id],
            'config' => [
                'tool_execution' => [
                    'timeout' => 30000,
                    'retry_count' => 2,
                ],
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('agents.store'), $data)
            ->assertRedirect();

        $agent = Agent::where('name', 'My Action Agent')->first();
        expect($agent->tools)->toHaveCount(1);
        expect($agent->tools->first()->id)->toBe($tool->id);
    });

    it('validates required fields', function () {
        $this->actingAs($this->user)
            ->post(route('agents.store'), [])
            ->assertSessionHasErrors(['type', 'name', 'model']);
    });
});

describe('show', function () {
    it('displays a standalone agent with its relationships', function () {
        $agent = Agent::factory()->standalone()->knowledge()->create(['user_id' => $this->user->id]);
        $kb = KnowledgeBase::factory()->ready()->create(['user_id' => $this->user->id]);
        $agent->knowledgeBases()->attach($kb);

        $this->actingAs($this->user)
            ->get(route('agents.show', $agent))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('standalone-agents/Show')
                ->where('agent.id', $agent->id)
                ->has('agent.knowledge_bases', 1)
            );
    });

    it('returns 403 for agents belonging to other users', function () {
        $otherAgent = Agent::factory()->standalone()->create();

        $this->actingAs($this->user)
            ->get(route('agents.show', $otherAgent))
            ->assertForbidden();
    });
});

describe('edit', function () {
    it('displays the edit form for a standalone agent', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('agents.edit', $agent))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('standalone-agents/Edit')
                ->where('agent.id', $agent->id)
            );
    });

    it('returns 403 for agents belonging to other users', function () {
        $otherAgent = Agent::factory()->standalone()->create();

        $this->actingAs($this->user)
            ->get(route('agents.edit', $otherAgent))
            ->assertForbidden();
    });
});

describe('update', function () {
    it('updates a standalone agent', function () {
        $agent = Agent::factory()->standalone()->triage()->create(['user_id' => $this->user->id]);

        $data = [
            'name' => 'Updated Agent Name',
            'description' => 'Updated description',
            'status' => AgentStatus::Active->value,
            'model' => 'gpt-4o-mini',
            'config' => [
                'temperature' => 0.5,
            ],
        ];

        $this->actingAs($this->user)
            ->put(route('agents.update', $agent), $data)
            ->assertRedirect();

        $agent->refresh();
        expect($agent->name)->toBe('Updated Agent Name');
        expect($agent->status)->toBe(AgentStatus::Active);
        expect($agent->model)->toBe('gpt-4o-mini');
    });

    it('updates knowledge base associations', function () {
        $agent = Agent::factory()->standalone()->knowledge()->create(['user_id' => $this->user->id]);
        $kb1 = KnowledgeBase::factory()->ready()->create(['user_id' => $this->user->id]);
        $kb2 = KnowledgeBase::factory()->ready()->create(['user_id' => $this->user->id]);
        $agent->knowledgeBases()->attach($kb1);

        $data = [
            'name' => $agent->name,
            'model' => $agent->model,
            'knowledge_base_ids' => [$kb2->id],
        ];

        $this->actingAs($this->user)
            ->put(route('agents.update', $agent), $data)
            ->assertRedirect();

        $agent->refresh();
        expect($agent->knowledgeBases)->toHaveCount(1);
        expect($agent->knowledgeBases->first()->id)->toBe($kb2->id);
    });

    it('returns 403 when updating agents belonging to other users', function () {
        $otherAgent = Agent::factory()->standalone()->create();

        $this->actingAs($this->user)
            ->put(route('agents.update', $otherAgent), [
                'name' => 'Hacked',
                'model' => 'claude-sonnet-4-20250514',
            ])
            ->assertForbidden();
    });
});

describe('destroy', function () {
    it('deletes a standalone agent', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->delete(route('agents.destroy', $agent))
            ->assertRedirect(route('agents.index'));

        $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
    });

    it('returns 403 when deleting agents belonging to other users', function () {
        $otherAgent = Agent::factory()->standalone()->create();

        $this->actingAs($this->user)
            ->delete(route('agents.destroy', $otherAgent))
            ->assertForbidden();
    });
});

describe('duplicate', function () {
    it('duplicates a standalone agent', function () {
        $agent = Agent::factory()->standalone()->triage()->create([
            'user_id' => $this->user->id,
            'name' => 'Original Agent',
        ]);

        $this->actingAs($this->user)
            ->post(route('agents.duplicate', $agent))
            ->assertRedirect();

        $this->assertDatabaseHas('agents', [
            'user_id' => $this->user->id,
            'name' => 'Original Agent (Copy)',
            'status' => AgentStatus::Draft->value,
        ]);
    });

    it('duplicates agent with its relationships', function () {
        $agent = Agent::factory()->standalone()->knowledge()->create(['user_id' => $this->user->id]);
        $kb = KnowledgeBase::factory()->ready()->create(['user_id' => $this->user->id]);
        $agent->knowledgeBases()->attach($kb);

        $this->actingAs($this->user)
            ->post(route('agents.duplicate', $agent))
            ->assertRedirect();

        $copy = Agent::where('name', $agent->name.' (Copy)')->first();
        expect($copy->knowledgeBases)->toHaveCount(1);
    });

    it('returns 403 when duplicating agents belonging to other users', function () {
        $otherAgent = Agent::factory()->standalone()->create();

        $this->actingAs($this->user)
            ->post(route('agents.duplicate', $otherAgent))
            ->assertForbidden();
    });
});
