<?php

use App\Ai\Tools\Builder\ListAvailableIntegrationsTool;
use App\Ai\Tools\Builder\ListConnectorActionsTool;
use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Models\Integration;
use App\Models\Tool;
use App\Models\User;
use App\Services\Connectors\ConnectorActionResolver;
use Laravel\Ai\Tools\Request as ToolRequest;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('lists the tenant integrations with an authorized flag', function () {
    $none = Integration::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'auth_type' => 'none',
        'status' => 'active',
        'name' => 'Open API',
    ]);

    $tool = new ListAvailableIntegrationsTool($this->user);
    $result = json_decode($tool->handle(new ToolRequest([])), true);

    expect($result['integrations'])->toHaveCount(1);
    expect($result['integrations'][0])->toMatchArray([
        'id' => $none->id,
        'name' => 'Open API',
        'auth_type' => 'none',
        'authorized' => true,
    ]);
});

it('lists the typed connector actions bound to an integration', function () {
    $integration = Integration::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
    ]);

    Tool::factory()->create([
        'type' => ToolType::RestApi,
        'status' => AgentStatus::Active,
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'name' => 'Get deal',
        'config' => [
            'base_url' => 'https://api.example.com',
            'method' => 'GET',
            'path' => '/deals/{{deal_id}}',
            'integration_id' => $integration->id,
        ],
    ]);

    // A tool on another integration must not leak into the listing.
    Tool::factory()->create([
        'type' => ToolType::RestApi,
        'status' => AgentStatus::Active,
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'config' => ['base_url' => 'https://other.example.com', 'method' => 'POST', 'integration_id' => 'integ_other'],
    ]);

    $tool = new ListConnectorActionsTool(app(ConnectorActionResolver::class), $this->user);
    $result = json_decode($tool->handle(new ToolRequest(['integration_id' => $integration->id])), true);

    expect($result['integration_id'])->toBe($integration->id);
    expect($result['actions'])->toHaveCount(1);
    expect($result['actions'][0])->toMatchArray([
        'name' => 'Get deal',
        'effect' => 'read',
        'tool_type' => 'rest_api',
    ]);
    expect(collect($result['actions'][0]['inputs'])->pluck('name')->all())->toBe(['deal_id']);
});

it('requires an integration_id', function () {
    $tool = new ListConnectorActionsTool(app(ConnectorActionResolver::class), $this->user);
    $result = json_decode($tool->handle(new ToolRequest([])), true);

    expect($result['ok'])->toBeFalse();
});
