<?php

use App\Ai\Tools\Builder\SampleMcpToolTool;
use App\Models\Integration;
use App\Models\User;
use App\Services\Tools\McpClient;
use Laravel\Ai\Tools\Request;

/**
 * The builder's MCP sampling tool: without a tool_name it lists the server's
 * tools (discovery); with one it calls it and returns real data — so the builder
 * can model + seed from a live MCP source instead of inventing demo data.
 */
it('lists the MCP server tools when no tool_name is given', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->create([
        'user_id' => $user->id,
        'kind' => 'mcp',
        'base_url' => 'https://go.example.com/mcp/x',
        'auth_type' => 'oauth2_auth_code',
    ]);

    $client = Mockery::mock(McpClient::class);
    $client->shouldReceive('listTools')
        ->once()
        ->withArgs(function (array $config, ?User $u) use ($integration, $user) {
            // Config is built from the integration; OAuth maps to the 'oauth2'
            // scheme + integration_id (per-user token resolution).
            return $config['endpoint'] === $integration->base_url
                && $config['integration_id'] === $integration->id
                && $config['auth_type'] === 'oauth2'
                && $u->is($user);
        })
        ->andReturn([
            ['name' => 'list_tickets', 'description' => 'List tickets', 'input_schema' => []],
        ]);

    $out = json_decode(
        (new SampleMcpToolTool($client, $user))->handle(new Request(['integration_id' => $integration->id])),
        true,
    );

    expect($out['ok'])->toBeTrue()
        ->and($out['tools'][0]['name'])->toBe('list_tickets');
});

it('calls the named MCP tool with arguments and returns its result', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->create([
        'user_id' => $user->id, 'kind' => 'mcp',
        'base_url' => 'https://go.example.com/mcp/x', 'auth_type' => 'oauth2_auth_code',
    ]);

    $client = Mockery::mock(McpClient::class);
    $client->shouldReceive('callTool')
        ->once()
        ->with(Mockery::type('array'), Mockery::type(User::class), 'list_tickets', ['limit' => 5])
        ->andReturn('[{"id":1,"estado":"abierto"}]');

    $out = json_decode(
        (new SampleMcpToolTool($client, $user))->handle(new Request([
            'integration_id' => $integration->id,
            'tool_name' => 'list_tickets',
            'arguments' => ['limit' => 5],
        ])),
        true,
    );

    expect($out['ok'])->toBeTrue()
        ->and($out['tool_name'])->toBe('list_tickets')
        ->and($out['result'])->toContain('abierto');
});

it('refuses a non-MCP integration', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->create([
        'user_id' => $user->id, 'kind' => 'http', 'auth_type' => 'none',
    ]);

    $out = json_decode(
        (new SampleMcpToolTool(Mockery::mock(McpClient::class), $user))
            ->handle(new Request(['integration_id' => $integration->id])),
        true,
    );

    expect($out['ok'])->toBeFalse()
        ->and($out['error'])->toContain('not an MCP server');
});

it('surfaces the MCP error instead of pretending it worked', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->create([
        'user_id' => $user->id, 'kind' => 'mcp',
        'base_url' => 'https://go.example.com/mcp/x', 'auth_type' => 'oauth2_auth_code',
    ]);

    $client = Mockery::mock(McpClient::class);
    $client->shouldReceive('listTools')->once()->andThrow(new RuntimeException('You have not authorized this tool yet.'));

    $out = json_decode(
        (new SampleMcpToolTool($client, $user))->handle(new Request(['integration_id' => $integration->id])),
        true,
    );

    expect($out['ok'])->toBeFalse()
        ->and($out['error'])->toContain('authorized');
});
