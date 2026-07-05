<?php

use App\Models\Integration;
use App\Models\User;
use App\Services\Connected\ConnectedObjectReader;
use App\Services\Integrations\IntegrationCaller;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\ExpressionResolver;
use App\Services\Tools\McpClient;
use Illuminate\Support\Str;

/**
 * Read-path slice for an MCP-backed connected object: a dashboard reads a
 * support desk (etc.) LIVE by calling an MCP tool as the acting viewer, mapping
 * the structured rows through the shared field_map/id_path — no seeding.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1',
        'is_mcp' => true,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'draft',
    ]);
});

function mcpTicketObject(string $integrationId): array
{
    return [
        'id' => 'obj_ticketobj',
        'slug' => 'tickets',
        'name' => 'Ticket',
        'fields' => [
            ['id' => 'fld_statusfield', 'slug' => 'status', 'name' => 'Status', 'type' => 'string'],
            ['id' => 'fld_minutesfield', 'slug' => 'minutes', 'name' => 'Minutes', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'integration_id' => $integrationId,
            'id_path' => 'ticket_id',
            'operations' => ['list' => ['mcp_tool' => 'list_tickets', 'arguments' => ['limit' => 500], 'collection_path' => 'tickets']],
            'field_map' => [
                ['field_id' => 'fld_statusfield', 'external_path' => 'status'],
                ['field_id' => 'fld_minutesfield', 'external_path' => 'metrics.resolution_minutes'],
            ],
        ],
    ];
}

it('reads with the acting viewer so a per-user OAuth source resolves their token', function () {
    $viewer = $this->user;
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args, $max = null) => $name === 'list_tickets'
            && $user?->is($viewer) === true                 // the viewer, not null
            && ($args['limit'] ?? null) === 500
            && ($config['integration_id'] ?? null) === $this->integration->id)
        ->andReturn(['tickets' => [
            ['ticket_id' => 'T1', 'status' => 'abierto', 'metrics' => ['resolution_minutes' => 30]],
            ['ticket_id' => 'T2', 'status' => 'cerrado', 'metrics' => ['resolution_minutes' => 90]],
        ]]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list(mcpTicketObject($this->integration->id), $this->integration, [], $viewer);

    expect($result['ok'])->toBeTrue()
        ->and($result['rows'])->toHaveCount(2)
        ->and($result['rows'][0]['status'])->toBe('abierto')
        ->and($result['rows'][0]['minutes'])->toBe(30)
        ->and($result['rows'][0]['_external_id'])->toBe('T1')
        ->and($result['rows'][1]['status'])->toBe('cerrado');
});

it('turns a per-user OAuth failure into an authorize-the-connection message', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->once()->andThrow(new RuntimeException('OAuth 2.0 MCP tools require a user context to resolve the token.'));

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list(mcpTicketObject($this->integration->id), $this->integration);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('authorize the connection');
});

it('reports did-not-return-JSON-rows when the tool answers in prose only', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->once()->andReturnNull();

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list(mcpTicketObject($this->integration->id), $this->integration);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('did not return JSON rows');
});

it('accepts a connected object with an mcp_tool source in the manifest schema', function () {
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_'.strtolower((string) Str::ulid()),
        'slug' => 'mini_'.strtolower(Str::random(6)),
        'name' => 'Live tickets',
        'version' => 1,
        'objects' => [mcpTicketObject('itg_yuhugo')],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_adminrole', 'slug' => 'admin', 'name' => 'Admin']]],
    ];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('resolves rolling-window expressions in operation arguments per read', function () {
    $today = now()->utc()->toDateString();
    $monthAgo = now()->utc()->subDays(30)->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = [
        'from' => '{{days_ago(30)}}',
        'to' => '{{today()}}',
        'granularity' => 'weekly',
    ];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $monthAgo
            && $args['to'] === $today
            && $args['granularity'] === 'weekly')
        ->andReturn(['tickets' => []]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list($object, $this->integration, [], $this->user);

    expect($result['ok'])->toBeTrue();
});
