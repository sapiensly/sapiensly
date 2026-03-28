<?php

use App\Enums\FlowActionType;
use App\Models\Agent;
use App\Models\Flow;
use App\Models\User;
use App\Services\FlowExecutorService;

beforeEach(function () {
    $this->executor = app(FlowExecutorService::class);
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->create(['user_id' => $this->user->id, 'type' => 'triage']);
});

test('initializes flow state at start node', function () {
    $flow = Flow::factory()->forAgent($this->agent)->withSimpleMenuFlow()->create();

    $state = $this->executor->initializeFlow($flow);

    expect($state)
        ->toHaveKey('flow_id', $flow->id)
        ->toHaveKey('current_node_id', 'node_start')
        ->toHaveKey('completed', false);
});

test('processes start node and advances to next node', function () {
    $flow = Flow::factory()->forAgent($this->agent)->withSimpleMenuFlow()->create();

    $state = $this->executor->initializeFlow($flow);
    $action = $this->executor->processInput($flow, $state, '');

    expect($action->type)->toBe(FlowActionType::ShowMenu);
    expect($action->data['message'])->toBe('How can I help you?');
    expect($action->data['options'])->toHaveCount(3);
});

test('processes menu selection by index', function () {
    $flow = Flow::factory()->forAgent($this->agent)->withSimpleMenuFlow()->create();

    // Advance to menu node first
    $state = $this->executor->initializeFlow($flow);
    $menuAction = $this->executor->processInput($flow, $state, '');

    // Select option 1 (Check order)
    $action = $this->executor->processInput($flow, $menuAction->updatedState, '1');

    expect($action->type)->toBe(FlowActionType::AgentHandoff);
    expect($action->data['target_agent'])->toBe('action');
    expect($action->data['context'])->toBe('check_order');
});

test('processes menu selection by label', function () {
    $flow = Flow::factory()->forAgent($this->agent)->withSimpleMenuFlow()->create();

    $state = $this->executor->initializeFlow($flow);
    $menuAction = $this->executor->processInput($flow, $state, '');

    $action = $this->executor->processInput($flow, $menuAction->updatedState, 'Other');

    expect($action->type)->toBe(FlowActionType::AgentHandoff);
    expect($action->data['target_agent'])->toBe('knowledge');
});

test('re-shows menu for unmatched input', function () {
    $flow = Flow::factory()->forAgent($this->agent)->withSimpleMenuFlow()->create();

    $state = $this->executor->initializeFlow($flow);
    $menuAction = $this->executor->processInput($flow, $state, '');

    $action = $this->executor->processInput($flow, $menuAction->updatedState, 'xyz unrelated');

    expect($action->type)->toBe(FlowActionType::ShowMenu);
});

test('processes message node and follows edge', function () {
    $flow = Flow::factory()->forAgent($this->agent)->withSimpleMenuFlow()->create();

    $state = $this->executor->initializeFlow($flow);
    $menuAction = $this->executor->processInput($flow, $state, '');

    // Select "Return item" (option 2)
    $action = $this->executor->processInput($flow, $menuAction->updatedState, '2');

    expect($action->type)->toBe(FlowActionType::SendMessage);
    expect($action->data['message'])->toBe('Let me help you with your return.');
});

test('should activate flow for new conversation', function () {
    Flow::factory()->forAgent($this->agent)->withSimpleMenuFlow()->active()->create();

    $shouldActivate = $this->executor->shouldActivateFlow($this->agent, 'hello', null);

    expect($shouldActivate)->toBeTrue();
});

test('should not activate flow when no active flow exists', function () {
    Flow::factory()->forAgent($this->agent)->withSimpleMenuFlow()->create(); // draft, not active

    $shouldActivate = $this->executor->shouldActivateFlow($this->agent, 'hello', null);

    expect($shouldActivate)->toBeFalse();
});

test('should continue existing flow', function () {
    Flow::factory()->forAgent($this->agent)->withSimpleMenuFlow()->active()->create();

    $flowState = ['flow_id' => 'test', 'current_node_id' => 'node_menu', 'completed' => false, 'history' => ['node_start', 'node_menu']];

    $shouldActivate = $this->executor->shouldActivateFlow($this->agent, 'hello', $flowState);

    expect($shouldActivate)->toBeTrue();
});

test('condition node with exact match', function () {
    $flow = Flow::factory()->forAgent($this->agent)->create([
        'definition' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                ['id' => 'cond', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 100], 'data' => [
                    'match_type' => 'exact',
                    'rules' => [
                        ['id' => 'match_yes', 'pattern' => 'yes', 'label' => 'Yes'],
                        ['id' => 'match_no', 'pattern' => 'no', 'label' => 'No'],
                    ],
                ]],
                ['id' => 'msg_yes', 'type' => 'message', 'position' => ['x' => 0, 'y' => 200], 'data' => ['message' => 'Great!']],
                ['id' => 'msg_no', 'type' => 'message', 'position' => ['x' => 200, 'y' => 200], 'data' => ['message' => 'Sorry!']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'cond'],
                ['id' => 'e2', 'source' => 'cond', 'target' => 'msg_yes', 'sourceHandle' => 'match_yes'],
                ['id' => 'e3', 'source' => 'cond', 'target' => 'msg_no', 'sourceHandle' => 'match_no'],
            ],
        ],
    ]);

    $state = $this->executor->initializeFlow($flow);
    // Start advances to condition node
    $this->executor->processInput($flow, $state, '');

    // Now at condition node, answer "yes"
    $condState = $state;
    $condState['current_node_id'] = 'cond';
    $condState['history'][] = 'cond';

    $action = $this->executor->processInput($flow, $condState, 'yes');

    expect($action->type)->toBe(FlowActionType::SendMessage);
    expect($action->data['message'])->toBe('Great!');
});
