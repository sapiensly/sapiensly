<?php

use App\Models\App;
use App\Models\Integration;
use App\Services\Connected\ConnectedIntegrationResolver;
use App\Services\Connected\ConnectedObjectReader;
use App\Services\Records\RecordQueryService;
use Illuminate\Support\Str;

/**
 * Connected objects store NOTHING in the records table, so every SQL read
 * answered "0 records" — the builder agent then mis-diagnosed a WORKING live
 * source as broken and offered to replace it with a demo snapshot (prod
 * yuhuticket). These pin the fix: the shared query service reads connected
 * objects LIVE through the same reader the dashboard renderer uses.
 */
beforeEach(function () {
    $this->testApp = App::factory()->create();
    $this->object = [
        'id' => 'obj_'.strtolower((string) Str::ulid()),
        'slug' => 'tickets_reason_cause_breakdown',
        'name' => 'Tickets Reason Cause Breakdown',
        'fields' => [
            ['id' => 'fld_reason0000x', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_totaltix00x', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'integration_id' => 'integ_fake',
            'operations' => ['list' => ['mcp_tool' => 'get-tickets-reason-cause-breakdown-tool', 'collection_path' => 'reasons']],
        ],
    ];
    $this->manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_'.strtolower((string) Str::ulid()),
        'slug' => 'test_app', 'name' => 'Test', 'version' => 1,
        'objects' => [$this->object],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_a', 'name' => 'Admin', 'slug' => 'admin', 'is_default' => true]]],
    ];

    $rows = [
        ['_external_id' => 'r1', 'reason' => 'Duplicado', 'total_tickets' => 80],
        ['_external_id' => 'r2', 'reason' => 'Retraso', 'total_tickets' => 65],
        ['_external_id' => 'r3', 'reason' => 'Defecto', 'total_tickets' => 20],
    ];

    $integrations = Mockery::mock(ConnectedIntegrationResolver::class);
    $integrations->shouldReceive('resolve')->andReturn(Integration::factory()->create(['is_mcp' => true]));
    $reader = Mockery::mock(ConnectedObjectReader::class);
    $reader->shouldReceive('list')->andReturn(['ok' => true, 'rows' => $rows]);

    $this->app->instance(ConnectedIntegrationResolver::class, $integrations);
    $this->app->instance(ConnectedObjectReader::class, $reader);
    $this->service = app(RecordQueryService::class);
});

it('queries a connected object live, with filters, sort and paging in memory', function () {
    $result = $this->service->queryWithMeta($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'gte', 'field_id' => 'fld_totaltix00x', 'value' => 50],
        'sort' => [['field_id' => 'fld_totaltix00x', 'direction' => 'desc']],
        'limit' => 1,
    ], $this->manifest);

    expect($result['total'])->toBe(2)
        ->and($result['has_more'])->toBeTrue()
        ->and($result['records']->first()->data['reason'])->toBe('Duplicado')
        ->and($result['records']->first()->id)->toBe('r1');
});

it('aggregates and group-aggregates connected rows in memory', function () {
    $sum = $this->service->aggregate(
        $this->testApp, ['object_id' => $this->object['id']], 'sum', 'fld_totaltix00x', $this->manifest,
    );
    expect($sum)->toEqual(165);

    $grouped = $this->service->groupedAggregate(
        $this->testApp, ['object_id' => $this->object['id']],
        'sum', 'fld_totaltix00x', 'fld_reason0000x', null, $this->manifest,
    );
    expect(collect($grouped)->firstWhere('group', 'Retraso')['value'])->toEqual(65);
});

it('finds one connected row by its external id', function () {
    $record = $this->service->find($this->testApp, $this->object['id'], 'r2', $this->manifest);

    expect($record)->not->toBeNull()
        ->and($record->data['reason'])->toBe('Retraso');
});
