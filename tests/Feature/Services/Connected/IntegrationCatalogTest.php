<?php

use App\Models\Integration;
use App\Models\User;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Connected\ConnectedObjectModeler;
use App\Services\Connected\IntegrationCatalog;
use App\Services\Tools\McpClient;
use App\Support\Tenancy\TenantCache;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1',
        'is_mcp' => true,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'active',
    ]);
});

it('caches the tool list — one RPC serves repeated builds', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->once()->andReturn([[
        'name' => 'search-tickets-tool',
        'description' => str_repeat('Long tool description. ', 30),
        'input_schema' => [
            'required' => ['query'],
            'properties' => ['limit' => ['type' => 'integer', 'maximum' => 100]],
        ],
    ]]);

    $catalog = new IntegrationCatalog($mcp, app(TenantCache::class));

    $first = $catalog->tools($this->integration, $this->user);
    $second = $catalog->tools($this->integration, $this->user); // cache hit — mock allows once()

    expect($second)->toBe($first)
        ->and($first[0]['name'])->toBe('search-tickets-tool')
        ->and(mb_strlen($first[0]['description']))->toBeLessThanOrEqual(203) // 200 + the "..." ellipsis
        ->and($first[0]['required'])->toBe(['query'])
        ->and($first[0]['arguments']['limit']['maximum'])->toBe(100)
        // The full schema stays available for argument clamping downstream.
        ->and($first[0]['input_schema']['properties']['limit']['maximum'])->toBe(100);
});

it('round-trips observed row shapes per tool', function () {
    $catalog = new IntegrationCatalog(Mockery::mock(McpClient::class), app(TenantCache::class));

    expect($catalog->knownShapes($this->integration))->toBe([]);

    $catalog->rememberShape($this->integration, 'search-tickets-tool', 'tickets', [
        ['path' => 'status', 'type' => 'string'],
        ['path' => 'created_at', 'type' => 'datetime'],
    ]);
    $catalog->rememberShape($this->integration, 'weekly-aggregates-tool', null, [
        ['path' => 'week', 'type' => 'date'],
    ]);

    $shapes = $catalog->knownShapes($this->integration);
    expect($shapes)->toHaveKeys(['search-tickets-tool', 'weekly-aggregates-tool'])
        ->and($shapes['search-tickets-tool']['collection_path'])->toBe('tickets')
        ->and($shapes['search-tickets-tool']['fields'])->toHaveCount(2);
});

it('remembers a summary-only tool as an empty shape after a no-rows read', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn([['name' => 'overview-tool', 'description' => '', 'input_schema' => []]]);
    $mcp->shouldReceive('callToolData')->andReturn(['totals' => ['open' => 5], 'scope' => 'x']);
    $catalog = new IntegrationCatalog($mcp, app(TenantCache::class));

    $authoring = new ConnectedObjectAuthoring(
        $mcp, new ConnectedObjectModeler, $catalog,
    );
    $result = $authoring->author($this->user, $this->integration, ['tool_name' => 'overview-tool'], ['objects' => []]);

    expect($result['ok'])->toBeFalse()
        ->and($catalog->knownShapes($this->integration)['overview-tool']['fields'])->toBe([]);
});
