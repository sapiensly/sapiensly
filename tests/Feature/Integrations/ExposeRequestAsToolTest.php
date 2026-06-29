<?php

use App\Enums\ToolType;
use App\Models\Integration;
use App\Models\IntegrationRequest;
use App\Models\Tool;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('promotes a saved request into a connected tool', function () {
    $integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
    ]);

    $request = IntegrationRequest::factory()->create([
        'integration_id' => $integration->id,
        'name' => 'Create Order',
        'method' => 'POST',
        'path' => '/orders',
        'headers' => [
            ['key' => 'X-Trace', 'value' => 'on', 'enabled' => true],
            ['key' => 'X-Ignore', 'value' => 'no', 'enabled' => false],
        ],
        'body_type' => 'json',
        'body_content' => '{"item": "{{item}}"}',
    ]);

    $this->actingAs($this->user)
        ->post("/system/integrations/requests/{$request->id}/expose-as-tool")
        ->assertRedirect();

    $tool = Tool::where('name', 'Create Order')->firstOrFail();

    expect($tool->type)->toBe(ToolType::RestApi);
    expect($tool->config['integration_id'])->toBe($integration->id);
    expect($tool->config['method'])->toBe('POST');
    expect($tool->config['path'])->toBe('/orders');
    expect($tool->config['headers'])->toBe(['X-Trace' => 'on']);
    expect($tool->config['request_body_template'])->toBe('{"item": "{{item}}"}');
    expect($tool->config)->not->toHaveKey('base_url');
});

it('forbids exposing a request on a connection the user cannot access', function () {
    $integration = Integration::factory()->create(); // owned by someone else
    $request = IntegrationRequest::factory()->create(['integration_id' => $integration->id]);

    $this->actingAs($this->user)
        ->post("/system/integrations/requests/{$request->id}/expose-as-tool")
        ->assertForbidden();
});
