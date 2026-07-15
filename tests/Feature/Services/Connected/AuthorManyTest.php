<?php

use App\Models\Integration;
use App\Models\User;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Connected\ConnectedObjectModeler;
use App\Services\Connected\IntegrationCatalog;
use App\Services\Tools\McpClient;
use App\Support\Tenancy\TenantCache;

/**
 * authorMany pools the current-window reads of a whole acquire batch into ONE
 * poolToolCalls round-trip (the serial author() paid N latencies). It must:
 * preserve spec order, keep slugs unique across the batch, isolate a per-spec
 * failure, and refire a date-constrained failure in a second pool — the same
 * outcomes author() produced one call at a time.
 */
function am_authoring(McpClient $mcp): ConnectedObjectAuthoring
{
    return new ConnectedObjectAuthoring($mcp, new ConnectedObjectModeler, new IntegrationCatalog($mcp, app(TenantCache::class)));
}

function am_tools(array $defs): array
{
    return array_map(fn (array $d): array => [
        'name' => $d['name'],
        'description' => $d['description'] ?? 'x',
        'input_schema' => $d['input_schema'] ?? [],
    ], $defs);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1',
        'is_mcp' => true,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'active',
    ]);
    $this->manifest = ['objects' => []];
});

it('pools every spec into one call, preserves order, and keeps slugs unique across the batch', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn(am_tools([['name' => 'get-tickets-tool']]));
    // Two specs deriving the SAME name ("Tickets") — authorMany must uniquify
    // the second slug against the first, exactly as the serial path did.
    $mcp->shouldReceive('poolToolCalls')
        ->once()
        ->withArgs(fn ($config, $user, $calls) => count($calls) === 2)
        ->andReturn([
            '0' => ['ok' => true, 'data' => ['rows' => [['id' => 'A', 'total' => 5]]]],
            '1' => ['ok' => true, 'data' => ['rows' => [['id' => 'B', 'total' => 9]]]],
        ]);

    $results = am_authoring($mcp)->authorMany($this->user, $this->integration, [
        ['tool_name' => 'get-tickets-tool'],
        ['tool_name' => 'get-tickets-tool'],
    ], $this->manifest);

    expect($results)->toHaveCount(2)
        ->and($results[0]['ok'])->toBeTrue()
        ->and($results[1]['ok'])->toBeTrue()
        // Order preserved: spec 0 got row A (total 5), spec 1 got row B (total 9).
        ->and($results[0]['rows'][0]['total'])->toBe(5)
        ->and($results[1]['rows'][0]['total'])->toBe(9)
        // Slugs unique across the batch.
        ->and($results[0]['object']['slug'])->toBe('tickets')
        ->and($results[1]['object']['slug'])->toBe('tickets_2');
});

it('isolates a per-spec failure: the survivor authors, the failure carries its error', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn(am_tools([['name' => 'get-a-tool'], ['name' => 'get-b-tool']]));
    $mcp->shouldReceive('poolToolCalls')->once()->andReturn([
        '0' => ['ok' => true, 'data' => ['rows' => [['id' => 'A', 'total' => 5]]]],
        '1' => ['ok' => false, 'error' => 'source read exploded'],
    ]);

    $results = am_authoring($mcp)->authorMany($this->user, $this->integration, [
        ['tool_name' => 'get-a-tool'],
        ['tool_name' => 'get-b-tool'],
    ], $this->manifest);

    expect($results[0]['ok'])->toBeTrue()
        ->and($results[1]['ok'])->toBeFalse()
        ->and($results[1]['error'])->toContain('source read exploded');
});

it('refires a date-constrained failure in a SECOND pool with a synthesized window', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn(am_tools([
        ['name' => 'get-win-tool', 'input_schema' => ['properties' => ['from' => ['type' => 'string']]]],
    ]));

    $poolCalls = [];
    $mcp->shouldReceive('poolToolCalls')
        ->twice()
        ->andReturnUsing(function ($config, $user, $calls) use (&$poolCalls) {
            $poolCalls[] = $calls;
            // First attempt (no window) fails; the retry (with `from`) succeeds.
            $hasWindow = array_key_exists('from', $calls['0']['arguments'] ?? []);

            return ['0' => $hasWindow
                ? ['ok' => true, 'data' => ['rows' => [['id' => 'A', 'total' => 5]]]]
                : ['ok' => false, 'error' => 'provide at least one date filter']];
        });

    $results = am_authoring($mcp)->authorMany($this->user, $this->integration, [
        ['tool_name' => 'get-win-tool'],
    ], $this->manifest);

    expect($poolCalls)->toHaveCount(2)                                   // first attempt + date retry
        ->and($poolCalls[0]['0']['arguments'])->not->toHaveKey('from')   // first had no window
        ->and($poolCalls[1]['0']['arguments'])->toHaveKey('from')        // retry synthesized one
        ->and($results[0]['ok'])->toBeTrue();
});
