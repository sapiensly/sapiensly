<?php

use App\Rules\ValidBotFlowDefinition;
use App\Services\BotFlows\BotFlowScaffolder;
use Illuminate\Support\Facades\Validator;

function assembleDefinitionValid(array $definition): bool
{
    return Validator::make(
        ['definition' => $definition],
        ['definition' => ['required', 'array', new ValidBotFlowDefinition]],
    )->passes();
}

function scaffolder(): BotFlowScaffolder
{
    return app(BotFlowScaffolder::class);
}

test('assemble builds a valid graph for a full triage/knowledge/action bot', function () {
    $def = scaffolder()->assemble(
        [
            'welcome_message' => 'Hi there!',
            'roles' => ['triage', 'knowledge', 'action'],
            'menu' => [
                'message' => 'How can I help?',
                'options' => [
                    ['label' => 'FAQ', 'route_role' => 'knowledge'],
                    ['label' => 'Refund', 'route_role' => 'action'],
                    ['label' => 'Something else', 'route_role' => null],
                ],
            ],
        ],
        ['triage' => [], 'knowledge' => [], 'action' => []],
    );

    expect(assembleDefinitionValid($def))->toBeTrue();

    $types = array_column($def['nodes'], 'type');
    expect($types)->toContain('start')
        ->and($types)->toContain('agent')
        ->and($types)->toContain('message')
        ->and($types)->toContain('menu')
        ->and($types)->toContain('agent_handoff');

    $agentRoles = collect($def['nodes'])->where('type', 'agent')->pluck('data.role')->all();
    expect($agentRoles)->toEqual(['triage', 'knowledge', 'action']);
});

test('assemble auto-binds the first available agent of each role', function () {
    $def = scaffolder()->assemble(
        ['roles' => ['knowledge'], 'menu' => null],
        ['triage' => [], 'knowledge' => [['id' => 'agent_k1', 'name' => 'Docs Bot']], 'action' => []],
    );

    $agentNode = collect($def['nodes'])->firstWhere('type', 'agent');
    expect($agentNode['data']['agent_id'])->toBe('agent_k1')
        ->and($agentNode['data']['agent_name'])->toBe('Docs Bot');
});

test('assemble produces a simple start to end flow with no menu', function () {
    $def = scaffolder()->assemble(
        ['welcome_message' => 'Welcome', 'roles' => ['triage'], 'menu' => null],
        ['triage' => [], 'knowledge' => [], 'action' => []],
    );

    expect(assembleDefinitionValid($def))->toBeTrue();
    $types = array_column($def['nodes'], 'type');
    expect($types)->toContain('start')->toContain('message')->toContain('end')
        ->and($types)->not->toContain('menu');
});

test('assemble emits an input chain for collected fields before the menu', function () {
    $def = scaffolder()->assemble(
        [
            'welcome_message' => 'Hi!',
            'roles' => ['triage'],
            'collect' => [
                ['prompt' => 'Your name?', 'variable' => 'name', 'input_type' => 'text'],
                ['prompt' => 'Your email?', 'variable' => 'email', 'input_type' => 'email'],
            ],
            'menu' => null,
        ],
        ['triage' => [], 'knowledge' => [], 'action' => []],
    );

    expect(assembleDefinitionValid($def))->toBeTrue();

    $inputs = collect($def['nodes'])->where('type', 'input')->values();
    expect($inputs)->toHaveCount(2)
        ->and($inputs[0]['data']['variable'])->toBe('name')
        ->and($inputs[1]['data']['variable'])->toBe('email')
        ->and($inputs[1]['data']['input_type'])->toBe('email');

    // The capture chain is wired in sequence from the welcome message.
    $sources = collect($def['edges'])->pluck('target')->all();
    expect($sources)->toContain($inputs[0]['id'])->toContain($inputs[1]['id']);
});

test('assemble routes a human menu option to a human_handoff node', function () {
    $def = scaffolder()->assemble(
        [
            'roles' => ['triage'],
            'menu' => [
                'message' => 'How can I help?',
                'options' => [
                    ['label' => 'FAQ', 'route_role' => 'knowledge'],
                    ['label' => 'Talk to a human', 'route_role' => 'human'],
                ],
            ],
        ],
        ['triage' => [], 'knowledge' => [], 'action' => []],
    );

    expect(assembleDefinitionValid($def))->toBeTrue();
    $types = array_column($def['nodes'], 'type');
    expect($types)->toContain('human_handoff')->toContain('agent_handoff');
});

test('assemble always emits exactly one start node', function () {
    $def = scaffolder()->assemble(['roles' => [], 'menu' => null], ['triage' => [], 'knowledge' => [], 'action' => []]);

    expect(collect($def['nodes'])->where('type', 'start'))->toHaveCount(1)
        ->and(assembleDefinitionValid($def))->toBeTrue();
});
