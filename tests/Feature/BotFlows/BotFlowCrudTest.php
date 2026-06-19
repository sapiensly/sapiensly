<?php

use App\Enums\BotFlowStatus;
use App\Models\Agent;
use App\Models\BotFlow;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->create(['user_id' => $this->user->id, 'type' => 'triage']);
});

test('flow index page is displayed', function () {
    BotFlow::factory()->forAgent($this->agent)->count(3)->create();

    $response = $this->actingAs($this->user)
        ->get(route('agents.flows.index', $this->agent));

    $response->assertOk();
});

test('flow create page is displayed', function () {
    $response = $this->actingAs($this->user)
        ->get(route('agents.flows.create', $this->agent));

    $response->assertOk();
});

test('can create a flow', function () {
    $response = $this->actingAs($this->user)
        ->post(route('agents.flows.store', $this->agent), [
            'name' => 'Test BotFlow',
            'description' => 'A test flow',
            'definition' => [
                'nodes' => [
                    ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                ],
                'edges' => [],
            ],
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('bot_flows', [
        'agent_id' => $this->agent->id,
        'name' => 'Test BotFlow',
        'status' => BotFlowStatus::Draft->value,
    ]);
});

test('rejects flow with invalid definition', function () {
    $response = $this->actingAs($this->user)
        ->post(route('agents.flows.store', $this->agent), [
            'name' => 'Bad BotFlow',
            'definition' => [
                'nodes' => [],
                'edges' => [],
            ],
        ]);

    $response->assertSessionHasErrors('definition');
});

test('can update a flow', function () {
    $flow = BotFlow::factory()->forAgent($this->agent)->create();

    $response = $this->actingAs($this->user)
        ->put(route('agents.flows.update', [$this->agent, $flow]), [
            'name' => 'Updated BotFlow',
        ]);

    $response->assertRedirect();

    expect($flow->fresh()->name)->toBe('Updated BotFlow');
});

test('can delete a flow', function () {
    $flow = BotFlow::factory()->forAgent($this->agent)->create();

    $response = $this->actingAs($this->user)
        ->delete(route('agents.flows.destroy', [$this->agent, $flow]));

    $response->assertRedirect();

    $this->assertDatabaseMissing('bot_flows', ['id' => $flow->id]);
});

test('can activate a flow', function () {
    $flow = BotFlow::factory()->forAgent($this->agent)->create();

    $response = $this->actingAs($this->user)
        ->post(route('agents.flows.activate', [$this->agent, $flow]));

    $response->assertRedirect();

    expect($flow->fresh()->status)->toBe(BotFlowStatus::Active);
});

test('activating a flow deactivates other active flows', function () {
    $flow1 = BotFlow::factory()->forAgent($this->agent)->active()->create();
    $flow2 = BotFlow::factory()->forAgent($this->agent)->create();

    $this->actingAs($this->user)
        ->post(route('agents.flows.activate', [$this->agent, $flow2]));

    expect($flow1->fresh()->status)->toBe(BotFlowStatus::Inactive);
    expect($flow2->fresh()->status)->toBe(BotFlowStatus::Active);
});

test('flow edit page is displayed', function () {
    $flow = BotFlow::factory()->forAgent($this->agent)->create();

    $response = $this->actingAs($this->user)
        ->get(route('agents.flows.edit', [$this->agent, $flow]));

    $response->assertOk();
});
