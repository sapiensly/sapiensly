<?php

use App\Ai\Tools\Builder\CreateIntegrationTool;
use App\Models\User;
use App\Services\Builder\Integrations\IntegrationAuthoring;
use Laravel\Ai\Tools\Request as ToolRequest;

it('stores a provisioning proposal with reason and actions', function () {
    $user = User::factory()->create();
    $tool = new CreateIntegrationTool(app(IntegrationAuthoring::class), $user);

    $result = json_decode($tool->handle(new ToolRequest([
        'name' => 'Slack',
        'base_url' => 'https://slack.com/api',
        'auth_type' => 'oauth2_auth_code',
        'reason' => 'to post the deal summary',
        'actions' => ['Post a message', ''],
    ])), true);

    expect($result['ok'])->toBeTrue();
    expect($result['authorize_required'])->toBeTrue();

    $proposal = $tool->proposal();
    expect($proposal)->toMatchArray([
        'name' => 'Slack',
        'auth_type' => 'oauth2_auth_code',
        'authorize_required' => true,
        'authorized' => false,
        'reason' => 'to post the deal summary',
    ]);
    expect($proposal['integration_id'])->toStartWith('integ_');
    // Empty action labels are dropped.
    expect($proposal['actions'])->toBe(['Post a message']);
});

it('marks a no-auth connection as already authorized', function () {
    $user = User::factory()->create();
    $tool = new CreateIntegrationTool(app(IntegrationAuthoring::class), $user);

    $tool->handle(new ToolRequest([
        'name' => 'Open API',
        'base_url' => 'https://api.example.com',
        'auth_type' => 'none',
    ]));

    expect($tool->proposal())->toMatchArray([
        'authorize_required' => false,
        'authorized' => true,
    ]);
});

it('has no proposal before any integration is created', function () {
    $user = User::factory()->create();
    $tool = new CreateIntegrationTool(app(IntegrationAuthoring::class), $user);

    expect($tool->proposal())->toBeNull();
});
