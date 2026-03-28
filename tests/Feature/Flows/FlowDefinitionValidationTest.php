<?php

use App\Rules\ValidFlowDefinition;
use Illuminate\Support\Facades\Validator;

function validateDefinition(array $definition): bool
{
    $validator = Validator::make(
        ['definition' => $definition],
        ['definition' => ['required', 'array', new ValidFlowDefinition]]
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
