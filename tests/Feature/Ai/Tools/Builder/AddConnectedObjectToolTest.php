<?php

use App\Ai\Tools\Builder\AddConnectedObjectTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Models\App;
use App\Models\Integration;
use App\Models\User;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Connected\ConnectedObjectModeler;
use App\Services\Connected\IntegrationCatalog;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use App\Services\Tools\McpClient;
use App\Support\Tenancy\TenantCache;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * The one-call MCP → connected object path: the server samples the tool,
 * clamps arguments to the input_schema, infers the fields and BANKS the object
 * through recordProposal — the model's output shrinks from a hand-written
 * 20-field patch (minutes of slow-model generation) to ~80 tokens.
 */
function aco_manifest(string $appId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini_'.strtolower(Str::random(6)),
        'name' => 'Mini',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->manifestService = app(AppManifestService::class);
    $this->manifestService->createVersion($this->testApp, aco_manifest($this->testApp->id), $this->user);

    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1',
        'is_mcp' => true,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'active',
    ]);
});

function aco_tool($test, McpClient $mcp): array
{
    $propose = new ProposeChangeTool($test->testApp->fresh(), $test->manifestService, app(ManifestValidator::class));
    $authoring = new ConnectedObjectAuthoring($mcp, new ConnectedObjectModeler, new IntegrationCatalog($mcp, app(TenantCache::class)));
    $tool = new AddConnectedObjectTool($propose, $authoring, $test->user);

    return [$tool, $propose];
}

it('models and banks a connected object from one call, clamping arguments to the schema', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->once()->andReturn([[
        'name' => 'search-tickets-tool',
        'description' => 'List tickets',
        'input_schema' => ['properties' => ['limit' => ['type' => 'integer', 'maximum' => 100]]],
    ]]);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $user?->is($this->user) === true
            && $name === 'search-tickets-tool'
            && ($args['limit'] ?? null) === 100)          // 500 clamped to the schema max
        ->andReturn(['tickets' => [
            ['id' => 'T1', 'status' => 'abierto', 'minutes' => 30, 'created_at' => '2026-07-01T10:00:00Z'],
            ['id' => 'T2', 'status' => 'cerrado', 'minutes' => 90, 'created_at' => '2026-07-02T11:00:00Z'],
        ]]);

    [$tool, $propose] = aco_tool($this, $mcp);
    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'tool_name' => 'search-tickets-tool',
        'arguments' => ['limit' => 500],
        'object_name' => 'Tickets YuhuGo',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['object']['slug'])->toBe('tickets_yuhugo')
        ->and($result['clamped_arguments']['limit']['to'])->toBe(100)
        ->and($result['sampled_rows'])->toBe(2)
        ->and($result['date_field_ids'])->toHaveCount(1);

    // The banked draft carries the full connected object, valid + auto-detected.
    $draft = $propose->currentManifest();
    $object = $draft['objects'][0];
    expect($object['source']['type'])->toBe('connected')
        ->and($object['source']['integration_id'])->toBe($this->integration->id)
        ->and($object['source']['id_path'])->toBe('id')
        ->and($object['source']['operations']['list']['mcp_tool'])->toBe('search-tickets-tool')
        ->and($object['source']['operations']['list']['arguments']['limit'])->toBe(100)
        ->and($object['source']['operations']['list']['collection_path'])->toBe('tickets')
        ->and($object['fields'])->toHaveCount(3)
        ->and($object['source']['field_map'])->toHaveCount(3);
});

it('rejects an unknown tool name, listing what the server exposes', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->once()->andReturn([
        ['name' => 'weekly-aggregates-tool', 'description' => '', 'input_schema' => []],
        ['name' => 'search-tickets-tool', 'description' => '', 'input_schema' => []],
    ]);

    [$tool] = aco_tool($this, $mcp);
    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'tool_name' => 'no-such-tool',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('weekly-aggregates-tool')
        ->and($result['errors'][0]['message'])->toContain('search-tickets-tool');
});

it('refuses a non-MCP integration and an empty result set with clear reasons', function () {
    $rest = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
        'is_mcp' => false,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'active',
    ]);

    $mcp = Mockery::mock(McpClient::class);
    [$tool] = aco_tool($this, $mcp);
    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $rest->id, 'tool_name' => 'x',
    ])), true);
    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('not an MCP server');

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn([['name' => 'empty-tool', 'description' => '', 'input_schema' => []]]);
    $mcp->shouldReceive('callToolData')->andReturn(['tickets' => []]);
    [$tool] = aco_tool($this, $mcp);
    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id, 'tool_name' => 'empty-tool',
    ])), true);
    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('returned no rows');
});

it('stores today-anchored date windows as rolling expressions, calling with literals', function () {
    $today = now()->utc()->toDateString();
    $monthAgo = now()->utc()->subDays(30)->toDateString();

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn([['name' => 'series-tool', 'description' => '', 'input_schema' => []]]);
    $mcp->shouldReceive('callToolData')
        ->once()
        // The sampling call itself uses the LITERAL dates the model passed.
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $monthAgo && $args['to'] === $today)
        ->andReturn(['series' => [['id' => 'W1', 'total' => 5]]]);

    [$tool, $propose] = aco_tool($this, $mcp);
    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'tool_name' => 'series-tool',
        'arguments' => ['from' => $monthAgo, 'to' => $today, 'granularity' => 'weekly'],
    ])), true);

    expect($result['ok'])->toBeTrue();

    // The STORED operation rolls: the window follows the clock, not the
    // authoring date.
    $stored = $propose->currentManifest()['objects'][0]['source']['operations']['list']['arguments'];
    expect($stored['from'])->toBe('{{days_ago(30)}}')
        ->and($stored['to'])->toBe('{{today()}}')
        ->and($stored['granularity'])->toBe('weekly');
});

it('leaves a fully historical window literal (deliberate range)', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn([['name' => 'series-tool', 'description' => '', 'input_schema' => []]]);
    $mcp->shouldReceive('callToolData')->andReturn(['series' => [['id' => 'W1', 'total' => 5]]]);

    [$tool, $propose] = aco_tool($this, $mcp);
    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'tool_name' => 'series-tool',
        'arguments' => ['from' => '2025-01-01', 'to' => '2025-03-31'],
    ])), true);

    expect($result['ok'])->toBeTrue();

    $stored = $propose->currentManifest()['objects'][0]['source']['operations']['list']['arguments'];
    expect($stored['from'])->toBe('2025-01-01')
        ->and($stored['to'])->toBe('2025-03-31');
});

it('fills required schema params the caller omitted with a rolling window', function () {
    $today = now()->utc()->toDateString();
    $monthAgo = now()->utc()->subDays(30)->toDateString();

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn([[
        'name' => 'series-tool',
        'description' => '',
        'input_schema' => [
            'required' => ['from', 'to', 'granularity'],
            'properties' => [
                'from' => ['type' => 'string', 'format' => 'date'],
                'to' => ['type' => 'string', 'format' => 'date'],
                'granularity' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly']],
            ],
        ],
    ]]);
    $mcp->shouldReceive('callToolData')
        ->once()
        // The Express pipeline names only the tool — the synthesized required
        // args must arrive: rolling window + the weekly-ish enum member.
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $monthAgo
            && $args['to'] === $today
            && $args['granularity'] === 'weekly')
        ->andReturn(['series' => [['id' => 'W1', 'total' => 5]]]);

    [$tool, $propose] = aco_tool($this, $mcp);
    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'tool_name' => 'series-tool',
    ])), true);

    expect($result['ok'])->toBeTrue();

    // Stored window rolls (today-anchored → expressions).
    $stored = $propose->currentManifest()['objects'][0]['source']['operations']['list']['arguments'];
    expect($stored['from'])->toBe('{{days_ago(30)}}')
        ->and($stored['to'])->toBe('{{today()}}')
        ->and($stored['granularity'])->toBe('weekly');
});

it('digs one level deeper for the rows list (nested by_dimension wrappers)', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn([['name' => 'vm-metrics-tool', 'description' => '', 'input_schema' => []]]);
    $mcp->shouldReceive('callToolData')->andReturn([
        'scope' => 'x', 'totals' => ['total' => 9],
        'by_dimension' => ['status' => [
            ['key' => 'abierto', 'count' => 5],
            ['key' => 'cerrado', 'count' => 4],
        ]],
    ]);

    [$tool, $propose] = aco_tool($this, $mcp);
    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'tool_name' => 'vm-metrics-tool',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['sampled_rows'])->toBe(2);

    $stored = $propose->currentManifest()['objects'][0]['source']['operations']['list'];
    expect($stored['collection_path'])->toBe('by_dimension.status');
});

it('fills fecha_desde/fecha_hasta style required params too', function () {
    $today = now()->utc()->toDateString();
    $monthAgo = now()->utc()->subDays(30)->toDateString();

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn([[
        'name' => 'ordenes-tool', 'description' => '',
        'input_schema' => [
            'required' => ['fecha_desde', 'fecha_hasta'],
            'properties' => [
                'fecha_desde' => ['type' => 'string', 'format' => 'date'],
                'fecha_hasta' => ['type' => 'string', 'format' => 'date'],
            ],
        ],
    ]]);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['fecha_desde'] === $monthAgo
            && $args['fecha_hasta'] === $today)
        ->andReturn(['ordenes' => [['id' => 'O1', 'total' => 5]]]);

    [$tool] = aco_tool($this, $mcp);
    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'tool_name' => 'ordenes-tool',
    ])), true);

    expect($result['ok'])->toBeTrue();
});

it('retries with a date window when the tool enforces at-least-one-filter only at call time', function () {
    // Prod: fecha_desde/fecha_hasta were OPTIONAL in the schema, but the tool
    // rejected every no-filter read with "Debes proporcionar al menos uno
    // de: sku, fecha_desde, fecha_hasta…" — a constraint that lives only in
    // the error message. One retry with the rolling window recovers it.
    $today = now()->utc()->toDateString();
    $monthAgo = now()->utc()->subDays(30)->toDateString();

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn([[
        'name' => 'ordenes-metrics-tool', 'description' => '',
        'input_schema' => [
            'properties' => [
                'sku' => ['type' => 'string'],
                'fecha_desde' => ['type' => 'string', 'format' => 'date'],
                'fecha_hasta' => ['type' => 'string', 'format' => 'date'],
            ],
        ],
    ]]);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args === [])
        ->andThrow(new RuntimeException('Debes proporcionar al menos uno de: `sku`, `fecha_desde`, `fecha_hasta`.'));
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => ($args['fecha_desde'] ?? null) === $monthAgo
            && ($args['fecha_hasta'] ?? null) === $today
            && ! array_key_exists('sku', $args))
        ->andReturn(['ordenes' => [['id' => 'O1', 'total' => 5]]]);

    [$tool, $propose] = aco_tool($this, $mcp);
    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'tool_name' => 'ordenes-metrics-tool',
    ])), true);

    expect($result['ok'])->toBeTrue();

    // The banked operation keeps the window (relativized), so future live
    // reads don't re-hit the constraint.
    $object = $propose->currentManifest()['objects'][0];
    expect($object['source']['operations']['list']['arguments']['fecha_hasta'])->toBe('{{today()}}')
        ->and($object['source']['operations']['list']['arguments']['fecha_desde'])->toBe('{{days_ago(30)}}');
});
