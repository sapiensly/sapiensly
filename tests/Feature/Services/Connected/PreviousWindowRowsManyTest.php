<?php

use App\Models\Integration;
use App\Models\User;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Connected\ConnectedObjectModeler;
use App\Services\Connected\IntegrationCatalog;
use App\Services\Tools\McpClient;
use App\Support\Tenancy\TenantCache;

/**
 * The pooled previous-window reader: the same span-back sample the serial
 * previousWindowRows() takes, but every acquired object's read collapses into
 * ONE poolToolCalls round-trip. It must shift each object's window back by its
 * own span, map results by object id, and stay best-effort — a window-less tool
 * or a failed read simply yields no delta.
 */
function pwm_authoring(McpClient $mcp): ConnectedObjectAuthoring
{
    return new ConnectedObjectAuthoring($mcp, new ConnectedObjectModeler, new IntegrationCatalog($mcp, app(TenantCache::class)));
}

function pwm_object(string $id, string $integrationId, array $arguments, mixed $collectionPath = null): array
{
    return [
        'id' => $id,
        'source' => ['operations' => ['list' => array_filter([
            'mcp_tool' => 'get-tickets-time-series-tool',
            'arguments' => $arguments,
            'collection_path' => $collectionPath,
        ], fn ($v) => $v !== null)]],
    ];
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
});

it('pools every windowed object into one call and shifts each window back a full span', function () {
    $a = pwm_object('obj_a', $this->integration->id, ['from' => '2026-06-01', 'to' => '2026-06-30']);
    $b = pwm_object('obj_b', $this->integration->id, ['desde' => '2026-05-01', 'hasta' => '2026-05-10']);

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('poolToolCalls')
        ->once()
        ->withArgs(function ($config, $user, $calls) {
            // One batch, keyed by object id, each window shifted a full span back.
            return $user?->is($this->user) === true
                && array_keys($calls) === ['obj_a', 'obj_b']
                && $calls['obj_a']['arguments']['from'] === '2026-05-03' // 29-day span back from 06-01
                && $calls['obj_a']['arguments']['to'] === '2026-06-01'
                && $calls['obj_b']['arguments']['desde'] === '2026-04-22' // 9-day span back from 05-01
                && $calls['obj_b']['arguments']['hasta'] === '2026-05-01';
        })
        ->andReturn([
            'obj_a' => ['ok' => true, 'data' => ['rows' => [['bucket' => 'W1', 'total' => 5]]]],
            'obj_b' => ['ok' => true, 'data' => ['rows' => [['bucket' => 'W2', 'total' => 8]]]],
        ]);

    $rows = pwm_authoring($mcp)->previousWindowRowsMany($this->user, $this->integration, [$a, $b]);

    expect($rows)->toHaveKeys(['obj_a', 'obj_b'])
        ->and($rows['obj_a'])->toBe([['bucket' => 'W1', 'total' => 5]])
        ->and($rows['obj_b'])->toBe([['bucket' => 'W2', 'total' => 8]]);
});

it('skips window-less objects entirely and never pools when none have a window', function () {
    $noWindow = pwm_object('obj_x', $this->integration->id, ['dimension' => 'reason']);

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldNotReceive('poolToolCalls');

    $rows = pwm_authoring($mcp)->previousWindowRowsMany($this->user, $this->integration, [$noWindow]);

    expect($rows)->toBe([]);
});

it('is best-effort per object: a failed or empty read yields no delta, the rest survive', function () {
    $ok = pwm_object('obj_ok', $this->integration->id, ['from' => '2026-06-01', 'to' => '2026-06-30']);
    $failed = pwm_object('obj_fail', $this->integration->id, ['from' => '2026-06-01', 'to' => '2026-06-30']);
    $empty = pwm_object('obj_empty', $this->integration->id, ['from' => '2026-06-01', 'to' => '2026-06-30']);

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('poolToolCalls')->once()->andReturn([
        'obj_ok' => ['ok' => true, 'data' => ['rows' => [['bucket' => 'W1', 'total' => 3]]]],
        'obj_fail' => ['ok' => false, 'error' => 'tool timed out'],
        'obj_empty' => ['ok' => true, 'data' => ['rows' => []]],
    ]);

    $rows = pwm_authoring($mcp)->previousWindowRowsMany($this->user, $this->integration, [$ok, $failed, $empty]);

    expect($rows)->toHaveKey('obj_ok')
        ->and($rows)->not->toHaveKey('obj_fail')
        ->and($rows)->not->toHaveKey('obj_empty')
        ->and($rows['obj_ok'])->toBe([['bucket' => 'W1', 'total' => 3]]);
});
