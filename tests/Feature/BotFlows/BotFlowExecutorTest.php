<?php

use App\Enums\BotFlowActionType;
use App\Models\Agent;
use App\Models\BotFlow;
use App\Models\User;
use App\Services\BotFlowExecutorService;

beforeEach(function () {
    $this->executor = app(BotFlowExecutorService::class);
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->create(['user_id' => $this->user->id, 'type' => 'triage']);
});

test('initializes flow state at start node', function () {
    $flow = BotFlow::factory()->withSimpleMenuFlow()->create();

    $state = $this->executor->initializeFlow($flow);

    expect($state)
        ->toHaveKey('flow_id', $flow->id)
        ->toHaveKey('current_node_id', 'node_start')
        ->toHaveKey('completed', false);
});

test('processes start node and advances to next node', function () {
    $flow = BotFlow::factory()->withSimpleMenuFlow()->create();

    $state = $this->executor->initializeFlow($flow);
    $action = $this->executor->processInput($flow, $state, '');

    expect($action->type)->toBe(BotFlowActionType::ShowMenu);
    expect($action->data['message'])->toBe('How can I help you?');
    expect($action->data['options'])->toHaveCount(3);
});

test('processes menu selection by index', function () {
    $flow = BotFlow::factory()->withSimpleMenuFlow()->create();

    // Advance to menu node first
    $state = $this->executor->initializeFlow($flow);
    $menuAction = $this->executor->processInput($flow, $state, '');

    // Select option 1 (Check order)
    $action = $this->executor->processInput($flow, $menuAction->updatedState, '1');

    expect($action->type)->toBe(BotFlowActionType::AgentHandoff);
    expect($action->data['target_agent'])->toBe('action');
    expect($action->data['context'])->toBe('check_order');
});

test('processes menu selection by label', function () {
    $flow = BotFlow::factory()->withSimpleMenuFlow()->create();

    $state = $this->executor->initializeFlow($flow);
    $menuAction = $this->executor->processInput($flow, $state, '');

    $action = $this->executor->processInput($flow, $menuAction->updatedState, 'Other');

    expect($action->type)->toBe(BotFlowActionType::AgentHandoff);
    expect($action->data['target_agent'])->toBe('knowledge');
});

test('re-shows menu for unmatched input', function () {
    $flow = BotFlow::factory()->withSimpleMenuFlow()->create();

    $state = $this->executor->initializeFlow($flow);
    $menuAction = $this->executor->processInput($flow, $state, '');

    $action = $this->executor->processInput($flow, $menuAction->updatedState, 'xyz unrelated');

    expect($action->type)->toBe(BotFlowActionType::ShowMenu);
});

test('processes message node and follows edge', function () {
    $flow = BotFlow::factory()->withSimpleMenuFlow()->create();

    $state = $this->executor->initializeFlow($flow);
    $menuAction = $this->executor->processInput($flow, $state, '');

    // Select "Return item" (option 2)
    $action = $this->executor->processInput($flow, $menuAction->updatedState, '2');

    expect($action->type)->toBe(BotFlowActionType::SendMessage);
    expect($action->data['message'])->toBe('Let me help you with your return.');
});

test('input node prompts for a value then waits', function () {
    $flow = BotFlow::factory()->create([
        'definition' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                ['id' => 'ask', 'type' => 'input', 'position' => ['x' => 0, 'y' => 100], 'data' => ['prompt' => 'Your email?', 'variable' => 'email', 'input_type' => 'email']],
                ['id' => 'done', 'type' => 'end', 'position' => ['x' => 0, 'y' => 200], 'data' => ['action' => 'resume_conversation']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'ask'],
                ['id' => 'e2', 'source' => 'ask', 'target' => 'done'],
            ],
        ],
    ]);

    $state = $this->executor->initializeFlow($flow);
    $action = $this->executor->processInput($flow, $state, '');

    expect($action->type)->toBe(BotFlowActionType::CollectInput);
    expect($action->data['prompt'])->toBe('Your email?');
    expect($action->data['variable'])->toBe('email');
    // Still parked on the input node, waiting for the reply.
    expect($action->updatedState['current_node_id'])->toBe('ask');
});

test('input node captures a valid value into flow variables and advances', function () {
    $flow = BotFlow::factory()->create([
        'definition' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                ['id' => 'ask', 'type' => 'input', 'position' => ['x' => 0, 'y' => 100], 'data' => ['prompt' => 'Your email?', 'variable' => 'email', 'input_type' => 'email']],
                ['id' => 'done', 'type' => 'end', 'position' => ['x' => 0, 'y' => 200], 'data' => ['action' => 'close_conversation']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'ask'],
                ['id' => 'e2', 'source' => 'ask', 'target' => 'done'],
            ],
        ],
    ]);

    $state = $this->executor->initializeFlow($flow);
    $prompt = $this->executor->processInput($flow, $state, '');

    $action = $this->executor->processInput($flow, $prompt->updatedState, 'jane@example.com');

    expect($action->type)->toBe(BotFlowActionType::End);
    expect($action->data['action'])->toBe('close_conversation');
    expect($action->updatedState['variables']['email'])->toBe('jane@example.com');
});

test('input node re-prompts when the value fails type validation', function () {
    $flow = BotFlow::factory()->create([
        'definition' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                ['id' => 'ask', 'type' => 'input', 'position' => ['x' => 0, 'y' => 100], 'data' => ['prompt' => 'Your email?', 'variable' => 'email', 'input_type' => 'email', 'error_message' => 'Bad email.']],
                ['id' => 'done', 'type' => 'end', 'position' => ['x' => 0, 'y' => 200], 'data' => ['action' => 'resume_conversation']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'ask'],
                ['id' => 'e2', 'source' => 'ask', 'target' => 'done'],
            ],
        ],
    ]);

    $state = $this->executor->initializeFlow($flow);
    $prompt = $this->executor->processInput($flow, $state, '');

    $action = $this->executor->processInput($flow, $prompt->updatedState, 'not-an-email');

    expect($action->type)->toBe(BotFlowActionType::CollectInput);
    expect($action->data['prompt'])->toBe('Bad email.');
    expect($action->data['invalid'])->toBeTrue();
    expect($action->updatedState)->not->toHaveKey('variables');
});

test('human handoff node ends the flow and signals escalation', function () {
    $flow = BotFlow::factory()->create([
        'definition' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                ['id' => 'human', 'type' => 'human_handoff', 'position' => ['x' => 0, 'y' => 100], 'data' => ['message' => 'Connecting you…', 'reason' => 'requested human', 'notify' => true]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'human'],
            ],
        ],
    ]);

    $state = $this->executor->initializeFlow($flow);
    $action = $this->executor->processInput($flow, $state, '');

    expect($action->type)->toBe(BotFlowActionType::HumanHandoff);
    expect($action->data['message'])->toBe('Connecting you…');
    expect($action->data['reason'])->toBe('requested human');
    expect($action->data['notify'])->toBeTrue();
    expect($action->updatedState['completed'])->toBeTrue();
});

test('condition node with exact match', function () {
    $flow = BotFlow::factory()->create([
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

    expect($action->type)->toBe(BotFlowActionType::SendMessage);
    expect($action->data['message'])->toBe('Great!');
});
