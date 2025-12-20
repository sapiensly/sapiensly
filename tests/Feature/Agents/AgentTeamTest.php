<?php

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Models\Agent;
use App\Models\AgentTeam;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('index', function () {
    it('displays the agent teams index page', function () {
        $this->actingAs($this->user)
            ->get(route('agent-teams.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('agents/Index'));
    });

    it('shows only teams belonging to the authenticated user', function () {
        $myTeam = AgentTeam::factory()->create(['user_id' => $this->user->id]);
        $otherTeam = AgentTeam::factory()->create();

        $this->actingAs($this->user)
            ->get(route('agent-teams.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('agents/Index')
                ->has('teams.data', 1)
                ->where('teams.data.0.id', $myTeam->id)
            );
    });
});

describe('create', function () {
    it('displays the create agent team form with standalone agents', function () {
        // Create some standalone agents
        Agent::factory()->triage()->create(['user_id' => $this->user->id, 'agent_team_id' => null]);
        Agent::factory()->knowledge()->create(['user_id' => $this->user->id, 'agent_team_id' => null]);

        $this->actingAs($this->user)
            ->get(route('agent-teams.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('agents/Create')
                ->has('agentTypes', 3)
                ->has('availableModels')
                ->has('standaloneAgents')
            );
    });
});

describe('store', function () {
    it('creates an agent team by selecting existing agents', function () {
        // Create standalone agents that belong to the user
        $triageAgent = Agent::factory()->triage()->create(['user_id' => $this->user->id, 'agent_team_id' => null]);
        $knowledgeAgent = Agent::factory()->knowledge()->create(['user_id' => $this->user->id, 'agent_team_id' => null]);
        $actionAgent = Agent::factory()->action()->create(['user_id' => $this->user->id, 'agent_team_id' => null]);

        $data = [
            'name' => 'Test Team',
            'description' => 'A test team',
            'agent_ids' => [
                'triage' => $triageAgent->id,
                'knowledge' => $knowledgeAgent->id,
                'action' => $actionAgent->id,
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('agent-teams.store'), $data)
            ->assertRedirect();

        $this->assertDatabaseHas('agent_teams', [
            'user_id' => $this->user->id,
            'name' => 'Test Team',
            'status' => AgentStatus::Draft->value,
        ]);

        $team = AgentTeam::where('name', 'Test Team')->first();
        expect($team->agents)->toHaveCount(3);

        // Verify agents are now attached to the team
        expect($triageAgent->fresh()->agent_team_id)->toBe($team->id);
        expect($knowledgeAgent->fresh()->agent_team_id)->toBe($team->id);
        expect($actionAgent->fresh()->agent_team_id)->toBe($team->id);
    });

    it('validates required fields', function () {
        $this->actingAs($this->user)
            ->post(route('agent-teams.store'), [])
            ->assertSessionHasErrors(['name', 'agent_ids']);
    });

    it('validates agent ownership', function () {
        $otherUser = User::factory()->create();

        // Create agents belonging to another user
        $triageAgent = Agent::factory()->triage()->create(['user_id' => $otherUser->id, 'agent_team_id' => null]);
        $knowledgeAgent = Agent::factory()->knowledge()->create(['user_id' => $this->user->id, 'agent_team_id' => null]);
        $actionAgent = Agent::factory()->action()->create(['user_id' => $this->user->id, 'agent_team_id' => null]);

        $data = [
            'name' => 'Test Team',
            'agent_ids' => [
                'triage' => $triageAgent->id,
                'knowledge' => $knowledgeAgent->id,
                'action' => $actionAgent->id,
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('agent-teams.store'), $data)
            ->assertForbidden();
    });
});

describe('show', function () {
    it('displays an agent team with its agents', function () {
        $team = AgentTeam::factory()->create(['user_id' => $this->user->id]);
        Agent::factory()->triage()->create(['agent_team_id' => $team->id]);
        Agent::factory()->knowledge()->create(['agent_team_id' => $team->id]);
        Agent::factory()->action()->create(['agent_team_id' => $team->id]);

        $this->actingAs($this->user)
            ->get(route('agent-teams.show', $team))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('agents/Show')
                ->where('team.id', $team->id)
                ->has('team.agents', 3)
            );
    });

    it('returns 403 for teams belonging to other users', function () {
        $otherTeam = AgentTeam::factory()->create();

        $this->actingAs($this->user)
            ->get(route('agent-teams.show', $otherTeam))
            ->assertForbidden();
    });
});

describe('edit', function () {
    it('displays the edit form for an agent team', function () {
        $team = AgentTeam::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('agent-teams.edit', $team))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('agents/Edit')
                ->where('team.id', $team->id)
            );
    });

    it('returns 403 for teams belonging to other users', function () {
        $otherTeam = AgentTeam::factory()->create();

        $this->actingAs($this->user)
            ->get(route('agent-teams.edit', $otherTeam))
            ->assertForbidden();
    });
});

describe('update', function () {
    it('updates an agent team', function () {
        $team = AgentTeam::factory()->create(['user_id' => $this->user->id]);
        Agent::factory()->triage()->create(['agent_team_id' => $team->id]);
        Agent::factory()->knowledge()->create(['agent_team_id' => $team->id]);
        Agent::factory()->action()->create(['agent_team_id' => $team->id]);

        $data = [
            'name' => 'Updated Team Name',
            'description' => 'Updated description',
            'status' => AgentStatus::Active->value,
            'agents' => [
                [
                    'type' => AgentType::Triage->value,
                    'name' => 'Updated Triage',
                    'model' => 'claude-sonnet-4-20250514',
                ],
                [
                    'type' => AgentType::Knowledge->value,
                    'name' => 'Updated Knowledge',
                    'model' => 'claude-sonnet-4-20250514',
                ],
                [
                    'type' => AgentType::Action->value,
                    'name' => 'Updated Action',
                    'model' => 'claude-sonnet-4-20250514',
                ],
            ],
        ];

        $this->actingAs($this->user)
            ->put(route('agent-teams.update', $team), $data)
            ->assertRedirect();

        $team->refresh();
        expect($team->name)->toBe('Updated Team Name');
        expect($team->status)->toBe(AgentStatus::Active);
    });
});

describe('destroy', function () {
    it('soft deletes an agent team', function () {
        $team = AgentTeam::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->delete(route('agent-teams.destroy', $team))
            ->assertRedirect(route('agent-teams.index'));

        $this->assertSoftDeleted('agent_teams', ['id' => $team->id]);
    });

    it('returns 403 when deleting teams belonging to other users', function () {
        $otherTeam = AgentTeam::factory()->create();

        $this->actingAs($this->user)
            ->delete(route('agent-teams.destroy', $otherTeam))
            ->assertForbidden();
    });
});
