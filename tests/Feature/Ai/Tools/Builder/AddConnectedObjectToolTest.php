<?php

use App\Ai\Tools\Builder\AddConnectedObjectTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Models\App;
use App\Models\Integration;
use App\Models\User;
use App\Services\Connected\ConnectedObjectModeler;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use App\Services\Tools\McpClient;
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
    $tool = new AddConnectedObjectTool($propose, $mcp, new ConnectedObjectModeler, $test->user);

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
