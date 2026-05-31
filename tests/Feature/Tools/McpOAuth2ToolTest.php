<?php

use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\Tool;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('lists only MCP connections on the create form', function () {
    $mcp = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create(['is_mcp' => true]);
    // A non-MCP OAuth integration must NOT appear as a connection.
    Integration::factory()->oauth2AuthCode()->forUser($this->user)->create(['is_mcp' => false]);

    $this->actingAs($this->user)
        ->get(route('tools.create', ['type' => 'mcp']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tools/Create')
            ->has('mcpConnections', 1)
            ->where('mcpConnections.0.id', $mcp->id)
            ->where('mcpConnections.0.connected', false)
        );
});

it('marks a connection as connected once the current user has a token', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create(['is_mcp' => true]);
    IntegrationUserToken::create([
        'user_id' => $this->user->id,
        'integration_id' => $integration->id,
        'auth_config' => ['access_token' => 'tok', 'expires_at' => time() + 3600],
    ]);

    $this->actingAs($this->user)
        ->get(route('tools.create', ['type' => 'mcp']))
        ->assertInertia(fn ($page) => $page
            ->where('mcpConnections.0.id', $integration->id)
            ->where('mcpConnections.0.connected', true)
        );
});

it('keeps connection state per-user — another member is not connected', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create(['is_mcp' => true]);
    $other = User::factory()->create(['organization_id' => $this->user->organization_id]);
    IntegrationUserToken::create([
        'user_id' => $other->id,
        'integration_id' => $integration->id,
        'auth_config' => ['access_token' => 'their-tok', 'expires_at' => time() + 3600],
    ]);

    $this->actingAs($this->user)
        ->get(route('tools.create', ['type' => 'mcp']))
        ->assertInertia(fn ($page) => $page
            ->where('mcpConnections.0.id', $integration->id)
            ->where('mcpConnections.0.connected', false)
        );
});

it('creates an MCP tool linked to an OAuth 2.0 integration', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->post(route('tools.store'), [
            'type' => 'mcp',
            'name' => 'My MCP',
            'config' => [
                'endpoint' => 'https://mcp.example.com/sse',
                'auth_type' => 'oauth2',
                'integration_id' => $integration->id,
            ],
        ])
        ->assertRedirect();

    $tool = Tool::query()->where('name', 'My MCP')->firstOrFail();

    expect($tool->config['auth_type'])->toBe('oauth2')
        ->and($tool->config['integration_id'])->toBe($integration->id);
});

it('rejects oauth2 MCP without a linked integration', function () {
    $this->actingAs($this->user)
        ->post(route('tools.store'), [
            'type' => 'mcp',
            'name' => 'My MCP',
            'config' => [
                'endpoint' => 'https://mcp.example.com/sse',
                'auth_type' => 'oauth2',
            ],
        ])
        ->assertSessionHasErrors('config.integration_id');
});

it('rejects an integration the user cannot access', function () {
    $foreign = Integration::factory()->oauth2AuthCode()->create();

    $this->actingAs($this->user)
        ->post(route('tools.store'), [
            'type' => 'mcp',
            'name' => 'My MCP',
            'config' => [
                'endpoint' => 'https://mcp.example.com/sse',
                'auth_type' => 'oauth2',
                'integration_id' => $foreign->id,
            ],
        ])
        ->assertSessionHasErrors('config.integration_id');
});

it('rejects a non-OAuth2 integration', function () {
    $apiKey = Integration::factory()->apiKey()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->post(route('tools.store'), [
            'type' => 'mcp',
            'name' => 'My MCP',
            'config' => [
                'endpoint' => 'https://mcp.example.com/sse',
                'auth_type' => 'oauth2',
                'integration_id' => $apiKey->id,
            ],
        ])
        ->assertSessionHasErrors('config.integration_id');
});
