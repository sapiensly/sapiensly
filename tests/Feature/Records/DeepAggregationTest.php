<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;

/**
 * Phase 3 aggregation depth: distinct_count (any field), and median/p90/p95
 * percentiles (numeric), across the SQL path (stored fields), the PHP fold
 * (derived fields) and grouped breakdowns.
 *
 * @return array<string, mixed>
 */
function ticketsManifest(): array
{
    return [
        'objects' => [[
            'id' => 'obj_tickets0001',
            'slug' => 'tickets',
            'name' => 'Tickets',
            'fields' => [
                ['id' => 'fld_tk_status01', 'slug' => 'status', 'name' => 'Status', 'type' => 'single_select', 'options' => [
                    ['id' => 'opt_open000001', 'value' => 'open', 'label' => 'Open'],
                    ['id' => 'opt_closed0001', 'value' => 'closed', 'label' => 'Closed'],
                ]],
                ['id' => 'fld_tk_cust0001', 'slug' => 'customer', 'name' => 'Customer', 'type' => 'string'],
                ['id' => 'fld_tk_hours001', 'slug' => 'hours', 'name' => 'Hours', 'type' => 'number'],
                ['id' => 'fld_tk_hoursx2', 'slug' => 'hours_x2', 'name' => 'Hours x2', 'type' => 'formula', 'readonly' => true, 'expression' => '{{hours * 2}}', 'return_type' => 'number'],
            ],
        ]],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->appModel = App::factory()->create(['user_id' => $this->user->id, 'slug' => 'tickets_app']);
    $this->manifest = ticketsManifest();
    $this->svc = app(RecordQueryService::class);

    // hours: 2,4,6,8,10 → median 6, p90 9.2, p95 9.6. customers: 3 distinct.
    $rows = [
        ['status' => 'open', 'customer' => 'ana', 'hours' => 2],
        ['status' => 'open', 'customer' => 'ana', 'hours' => 4],
        ['status' => 'closed', 'customer' => 'beto', 'hours' => 6],
        ['status' => 'closed', 'customer' => 'caro', 'hours' => 8],
        ['status' => 'closed', 'customer' => 'caro', 'hours' => 10],
    ];
    foreach ($rows as $data) {
        Record::create(['app_id' => $this->appModel->id, 'object_definition_id' => 'obj_tickets0001', 'data' => $data]);
    }
});

it('distinct_count counts unique values of any field', function () {
    $value = $this->svc->aggregate($this->appModel, ['object_id' => 'obj_tickets0001'], 'distinct_count', 'fld_tk_cust0001', $this->manifest);
    expect($value)->toBe(3); // ana, beto, caro
});

it('computes median, p90 and p95 percentiles over a numeric field', function () {
    $q = ['object_id' => 'obj_tickets0001'];

    expect((float) $this->svc->aggregate($this->appModel, $q, 'median', 'fld_tk_hours001', $this->manifest))->toBe(6.0);
    expect(round((float) $this->svc->aggregate($this->appModel, $q, 'p90', 'fld_tk_hours001', $this->manifest), 2))->toBe(9.2);
    expect(round((float) $this->svc->aggregate($this->appModel, $q, 'p95', 'fld_tk_hours001', $this->manifest), 2))->toBe(9.6);
});

it('computes a percentile over a derived (formula) field in PHP', function () {
    // hours_x2 = 4,8,12,16,20 → median 12.
    $value = $this->svc->aggregate($this->appModel, ['object_id' => 'obj_tickets0001'], 'median', 'fld_tk_hoursx2', $this->manifest);
    expect((float) $value)->toBe(12.0);
});

it('distinct_count over a derived field folds in PHP', function () {
    // hours_x2 has 5 distinct doubled values.
    $value = $this->svc->aggregate($this->appModel, ['object_id' => 'obj_tickets0001'], 'distinct_count', 'fld_tk_hoursx2', $this->manifest);
    expect($value)->toBe(5);
});

it('groups distinct_count by a field', function () {
    $groups = $this->svc->groupedAggregate($this->appModel, ['object_id' => 'obj_tickets0001'], 'distinct_count', 'fld_tk_cust0001', 'fld_tk_status01', null, $this->manifest);
    $byStatus = collect($groups)->pluck('value', 'group');

    expect($byStatus['open'])->toBe(1)    // ana
        ->and($byStatus['closed'])->toBe(2); // beto, caro
});

it('groups median by a field', function () {
    $groups = $this->svc->groupedAggregate($this->appModel, ['object_id' => 'obj_tickets0001'], 'median', 'fld_tk_hours001', 'fld_tk_status01', null, $this->manifest);
    $byStatus = collect($groups)->pluck('value', 'group');

    expect((float) $byStatus['open'])->toBe(3.0)     // median of 2,4
        ->and((float) $byStatus['closed'])->toBe(8.0); // median of 6,8,10
});

it('pivots a metric across two group dimensions', function () {
    // Add a second categorical field (channel) and re-seed so each row has one.
    $this->manifest['objects'][0]['fields'][] = ['id' => 'fld_tk_channel1', 'slug' => 'channel', 'name' => 'Channel', 'type' => 'single_select', 'options' => [
        ['id' => 'opt_web000001', 'value' => 'web', 'label' => 'Web'],
        ['id' => 'opt_phone00001', 'value' => 'phone', 'label' => 'Phone'],
    ]];
    Record::query()->where('app_id', $this->appModel->id)->delete();
    $rows = [
        ['status' => 'open', 'channel' => 'web', 'hours' => 2],
        ['status' => 'open', 'channel' => 'phone', 'hours' => 4],
        ['status' => 'closed', 'channel' => 'web', 'hours' => 6],
        ['status' => 'closed', 'channel' => 'web', 'hours' => 8],
    ];
    foreach ($rows as $data) {
        Record::create(['app_id' => $this->appModel->id, 'object_definition_id' => 'obj_tickets0001', 'data' => $data]);
    }

    $groups = $this->svc->groupedAggregate(
        $this->appModel, ['object_id' => 'obj_tickets0001'], 'count', null,
        'fld_tk_status01', null, $this->manifest, [], 100, secondGroupFieldId: 'fld_tk_channel1',
    );

    // Each entry carries both dimensions.
    $matrix = collect($groups)->mapWithKeys(fn ($g) => [$g['group'].'/'.$g['group2'] => $g['value']]);
    expect($matrix['open/web'])->toBe(1)
        ->and($matrix['open/phone'])->toBe(1)
        ->and($matrix['closed/web'])->toBe(2);
});

it('rejects a percentile on a non-numeric field', function () {
    expect(fn () => $this->svc->aggregate($this->appModel, ['object_id' => 'obj_tickets0001'], 'median', 'fld_tk_cust0001', $this->manifest))
        ->toThrow(InvalidArgumentException::class);
});

it('the manifest schema accepts distinct_count and percentile KPIs', function () {
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_deepagg0001',
        'slug' => 'tickets_app',
        'name' => 'Tickets',
        'version' => 1,
        'objects' => ticketsManifest()['objects'],
        'pages' => [[
            'id' => 'pag_dash_00001',
            'slug' => 'dashboard',
            'name' => 'Dashboard',
            'path' => '/',
            'blocks' => [[
                'id' => 'blk_kpis_00001',
                'type' => 'metric_grid',
                'items' => [
                    ['id' => 'itm_uniq_00001', 'label' => 'Unique customers', 'query' => ['object_id' => 'obj_tickets0001'], 'aggregation' => 'distinct_count', 'field_id' => 'fld_tk_cust0001'],
                    ['id' => 'itm_p95_000001', 'label' => 'P95 hours', 'query' => ['object_id' => 'obj_tickets0001'], 'aggregation' => 'p95', 'field_id' => 'fld_tk_hours001', 'format' => 'duration'],
                ],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin_0001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    $result = (new ManifestValidator)->validate($manifest);
    expect($result->valid)->toBeTrue();
});
