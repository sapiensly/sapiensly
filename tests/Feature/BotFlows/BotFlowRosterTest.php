<?php

use App\Models\Agent;
use App\Models\BotFlow;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function flowWithAgentNodes(User $user, array $roleToAgentId): BotFlow
{
    $nodes = [['id' => 'start', 'type' => 'start', 'data' => []]];
    foreach ($roleToAgentId as $role => $agentId) {
        $nodes[] = ['id' => "agent_{$role}", 'type' => 'agent', 'data' => ['role' => $role, 'agent_id' => $agentId]];
    }

    return BotFlow::factory()->create([
        'user_id' => $user->id,
        'definition' => ['nodes' => $nodes, 'edges' => []],
    ]);
}

test('roster resolves agents by role from agent nodes', function () {
    $triage = Agent::factory()->create(['user_id' => $this->user->id, 'type' => 'triage']);
    $knowledge = Agent::factory()->create(['user_id' => $this->user->id, 'type' => 'knowledge']);
    $action = Agent::factory()->create(['user_id' => $this->user->id, 'type' => 'action']);

    $flow = flowWithAgentNodes($this->user, [
        'triage' => $triage->id,
        'knowledge' => $knowledge->id,
        'action' => $action->id,
    ]);

    $roster = $flow->roster();

    expect($roster['triage']->id)->toBe($triage->id)
        ->and($roster['knowledge']->id)->toBe($knowledge->id)
        ->and($roster['action']->id)->toBe($action->id)
        ->and($flow->rosterAgents())->toHaveCount(3);
});

test('roster leaves missing roles null', function () {
    $triage = Agent::factory()->create(['user_id' => $this->user->id, 'type' => 'triage']);

    $flow = flowWithAgentNodes($this->user, ['triage' => $triage->id]);

    $roster = $flow->roster();

    expect($roster['triage']->id)->toBe($triage->id)
        ->and($roster['knowledge'])->toBeNull()
        ->and($roster['action'])->toBeNull()
        ->and($flow->rosterAgents())->toHaveCount(1);
});

test('a single-agent flow has a roster of one', function () {
    $agent = Agent::factory()->create(['user_id' => $this->user->id, 'type' => 'triage']);

    $flow = flowWithAgentNodes($this->user, ['triage' => $agent->id]);

    expect($flow->rosterAgents())->toHaveCount(1);
});
