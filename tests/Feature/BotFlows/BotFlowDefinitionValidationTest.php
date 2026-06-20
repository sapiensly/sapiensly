<?php

use App\Rules\ValidBotFlowDefinition;
use Illuminate\Support\Facades\Validator;

function validateDefinition(array $definition): bool
{
    $validator = Validator::make(
        ['definition' => $definition],
        ['definition' => ['required', 'array', new ValidBotFlowDefinition]]
    );

    return $validator->passes();
}

test('accepts valid flow definition with start node', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
        ],
        'edges' => [],
    ]))->toBeTrue();
});

test('rejects flow without start node', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_menu', 'type' => 'menu', 'position' => ['x' => 0, 'y' => 0], 'data' => ['message' => 'Hi', 'options' => [['id' => 'o1', 'label' => 'A']]]],
        ],
        'edges' => [],
    ]))->toBeFalse();
});

test('rejects flow with multiple start nodes', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'start1', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'start2', 'type' => 'start', 'position' => ['x' => 100, 'y' => 0], 'data' => []],
        ],
        'edges' => [],
    ]))->toBeFalse();
});

test('rejects edge with non-existent source node', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 'fake_node', 'target' => 'node_start'],
        ],
    ]))->toBeFalse();
});

test('rejects menu node without options', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'node_menu', 'type' => 'menu', 'position' => ['x' => 0, 'y' => 100], 'data' => ['message' => 'Hi', 'options' => []]],
        ],
        'edges' => [],
    ]))->toBeFalse();
});

test('rejects invalid node type', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'node_bad', 'type' => 'unknown_type', 'position' => ['x' => 0, 'y' => 100], 'data' => []],
        ],
        'edges' => [],
    ]))->toBeFalse();
});

test('accepts agent node with agent_id and valid role', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'node_agent', 'type' => 'agent', 'position' => ['x' => 0, 'y' => 100], 'data' => ['agent_id' => 'agent_123', 'role' => 'triage']],
        ],
        'edges' => [],
    ]))->toBeTrue();
});

test('accepts an unassigned agent node (draft, no agent_id yet)', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'node_agent', 'type' => 'agent', 'position' => ['x' => 0, 'y' => 100], 'data' => ['role' => 'triage']],
        ],
        'edges' => [],
    ]))->toBeTrue();
});

test('rejects agent node with invalid role', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'node_agent', 'type' => 'agent', 'position' => ['x' => 0, 'y' => 100], 'data' => ['agent_id' => 'agent_123', 'role' => 'supervisor']],
        ],
        'edges' => [],
    ]))->toBeFalse();
});

test('rejects agent_handoff with an invalid target_agent', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'node_handoff', 'type' => 'agent_handoff', 'position' => ['x' => 0, 'y' => 100], 'data' => ['target_agent' => 'agent_01abc']],
        ],
        'edges' => [],
    ]))->toBeFalse();
});

test('accepts agent_handoff with a valid target_agent role slug', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'node_handoff', 'type' => 'agent_handoff', 'position' => ['x' => 0, 'y' => 100], 'data' => ['target_agent' => 'knowledge']],
        ],
        'edges' => [],
    ]))->toBeTrue();
});

test('accepts an input node with a variable', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'node_input', 'type' => 'input', 'position' => ['x' => 0, 'y' => 100], 'data' => ['prompt' => 'Email?', 'variable' => 'email', 'input_type' => 'email']],
        ],
        'edges' => [],
    ]))->toBeTrue();
});

test('rejects an input node without a variable', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'node_input', 'type' => 'input', 'position' => ['x' => 0, 'y' => 100], 'data' => ['prompt' => 'Email?']],
        ],
        'edges' => [],
    ]))->toBeFalse();
});

test('accepts a human_handoff node', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'node_human', 'type' => 'human_handoff', 'position' => ['x' => 0, 'y' => 100], 'data' => ['message' => 'One moment…', 'notify' => true]],
        ],
        'edges' => [],
    ]))->toBeTrue();
});

test('accepts complete flow definition', function () {
    expect(validateDefinition([
        'nodes' => [
            ['id' => 'node_start', 'type' => 'start', 'position' => ['x' => 250, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
            ['id' => 'node_menu', 'type' => 'menu', 'position' => ['x' => 250, 'y' => 150], 'data' => ['message' => 'How can I help?', 'options' => [['id' => 'o1', 'label' => 'Check order'], ['id' => 'o2', 'label' => 'Other']]]],
            ['id' => 'node_handoff', 'type' => 'agent_handoff', 'position' => ['x' => 100, 'y' => 300], 'data' => ['target_agent' => 'action']],
            ['id' => 'node_end', 'type' => 'end', 'position' => ['x' => 400, 'y' => 300], 'data' => ['action' => 'resume_conversation']],
        ],
        'edges' => [
            ['id' => 'e1', 'source' => 'node_start', 'target' => 'node_menu'],
            ['id' => 'e2', 'source' => 'node_menu', 'target' => 'node_handoff', 'sourceHandle' => 'o1'],
            ['id' => 'e3', 'source' => 'node_menu', 'target' => 'node_end', 'sourceHandle' => 'o2'],
        ],
    ]))->toBeTrue();
});
