<?php

use App\Models\Agent;
use App\Models\User;
use App\Services\TriageRoutingService;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('triage routing service', function () {
    test('it builds an execution plan tool from the knowledge and action agents', function () {
        $knowledge = Agent::factory()->knowledge()->forUser($this->user)->create(['name' => 'Knowledge Bot']);
        $action = Agent::factory()->action()->active()->forUser($this->user)->create(['name' => 'Action Bot']);

        $tools = app(TriageRoutingService::class)->buildRoutingTools($knowledge, $action);

        expect($tools)->toHaveCount(1)
            ->and($tools[0]->name())->toBe('create_execution_plan');
    });

    test('it parses execution plan with multiple steps', function () {
        $stepsJson = '[{"agent":"knowledge","query":"refund policy","urgency":"high"},{"agent":"action","task":"check order #12345"}]';

        $plan = app(TriageRoutingService::class)->parseExecutionPlan($stepsJson);

        expect($plan)->toHaveCount(2)
            ->and($plan[0]['agent'])->toBe('knowledge')
            ->and($plan[0]['query'])->toBe('refund policy')
            ->and($plan[0]['urgency'])->toBe('high')
            ->and($plan[1]['agent'])->toBe('action')
            ->and($plan[1]['task'])->toBe('check order #12345');
    });

    test('it parses single step execution plan', function () {
        $plan = app(TriageRoutingService::class)->parseExecutionPlan('[{"agent":"direct","response":"Hello! How can I help you?"}]');

        expect($plan)->toHaveCount(1)
            ->and($plan[0]['agent'])->toBe('direct')
            ->and($plan[0]['response'])->toBe('Hello! How can I help you?');
    });

    test('it handles invalid JSON gracefully', function () {
        $plan = app(TriageRoutingService::class)->parseExecutionPlan('not valid json');

        expect($plan)->toHaveCount(1)
            ->and($plan[0]['agent'])->toBe('direct')
            ->and($plan[0]['response'])->toBe('not valid json');
    });
});
