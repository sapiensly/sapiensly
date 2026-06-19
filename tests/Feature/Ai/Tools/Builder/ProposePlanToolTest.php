<?php

use App\Ai\Tools\Builder\ProposePlanTool;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use Laravel\Ai\Tools\Request as ToolRequest;

it('records a structured plan and returns a stop instruction', function () {
    $tool = new ProposePlanTool;

    $result = json_decode($tool->handle(new ToolRequest([
        'summary' => 'When a deal closes in HubSpot, post to Slack and create a task.',
        'trigger' => 'HubSpot deal stage → closed',
        'steps' => [
            ['label' => 'Get deal details', 'effect' => 'read', 'integration' => 'HubSpot'],
            ['label' => 'Post summary to #sales', 'effect' => 'write', 'integration' => 'Slack'],
        ],
        'touches' => [
            ['system' => 'HubSpot', 'effect' => 'read'],
            ['system' => 'Slack', 'effect' => 'write'],
        ],
        'assumptions' => [
            ['label' => 'Slack channel', 'default' => '#sales'],
        ],
    ])), true);

    expect($result['ok'])->toBeTrue();
    expect($result['message'])->toContain('Do NOT edit the manifest yet');

    $plan = $tool->plan();
    expect($plan['trigger'])->toBe('HubSpot deal stage → closed');
    expect($plan['steps'])->toHaveCount(2);
    expect($plan['touches'])->toEqual([
        ['system' => 'HubSpot', 'effect' => 'read'],
        ['system' => 'Slack', 'effect' => 'write'],
    ]);
    expect($plan['assumptions'][0])->toMatchArray(['label' => 'Slack channel', 'default' => '#sales']);
});

it('drops scalar entries that slip into list fields', function () {
    $tool = new ProposePlanTool;

    $tool->handle(new ToolRequest([
        'summary' => 'x',
        'trigger' => 'y',
        'steps' => ['not-an-object', ['label' => 'Real step']],
    ]));

    expect($tool->plan()['steps'])->toEqual([['label' => 'Real step']]);
});

it('returns null before any plan is proposed', function () {
    expect((new ProposePlanTool)->plan())->toBeNull();
});

it('persists and casts a plan on a builder message', function () {
    $user = User::factory()->create();
    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $user->organization_id]);
    $conversation = BuilderConversation::create([
        'organization_id' => $app->organization_id,
        'app_id' => $app->id,
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $message = BuilderMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Here is the plan.',
        'plan' => ['summary' => 'Do a thing', 'touches' => [['system' => 'Slack', 'effect' => 'write']]],
        'status' => 'none',
    ]);

    expect($message->fresh()->plan)->toEqual([
        'summary' => 'Do a thing',
        'touches' => [['system' => 'Slack', 'effect' => 'write']],
    ]);
});
