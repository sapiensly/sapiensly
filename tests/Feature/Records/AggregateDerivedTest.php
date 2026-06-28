<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;

/**
 * @return array<string, mixed>
 */
function ordersManifest(): array
{
    return [
        'objects' => [
            [
                'id' => 'obj_orders_0001',
                'slug' => 'orders',
                'name' => 'Orders',
                'fields' => [
                    ['id' => 'fld_ord_name001', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                    ['id' => 'fld_ord_lines01', 'slug' => 'lines', 'name' => 'Lines', 'type' => 'relation', 'cardinality' => 'one_to_many', 'target_object_id' => 'obj_lines_00001', 'inverse_field_id' => 'fld_lin_order01'],
                    ['id' => 'fld_ord_total01', 'slug' => 'total', 'name' => 'Total', 'type' => 'rollup', 'readonly' => true, 'via_relation_field_id' => 'fld_ord_lines01', 'aggregator' => 'sum', 'target_field_id' => 'fld_lin_sub001', 'currency_code' => 'MXN'],
                ],
            ],
            [
                'id' => 'obj_lines_00001',
                'slug' => 'lines',
                'name' => 'Lines',
                'fields' => [
                    ['id' => 'fld_lin_sub001', 'slug' => 'subtotal', 'name' => 'Subtotal', 'type' => 'currency', 'currency_code' => 'MXN'],
                    ['id' => 'fld_lin_order01', 'slug' => 'order', 'name' => 'Order', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_orders_0001', 'inverse_field_id' => 'fld_ord_lines01'],
                    ['id' => 'fld_lin_tax001', 'slug' => 'subtotal_tax', 'name' => 'Subtotal + IVA', 'type' => 'formula', 'readonly' => true, 'expression' => '{{subtotal * 1.16}}', 'return_type' => 'number'],
                ],
            ],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->appModel = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'orders_app',
    ]);
    $this->manifest = ordersManifest();

    $writer = app(RecordWriteService::class);
    $orderA = $writer->create($this->appModel, $this->manifest, 'obj_orders_0001', ['name' => 'A'], $this->user);
    $orderB = $writer->create($this->appModel, $this->manifest, 'obj_orders_0001', ['name' => 'B'], $this->user);

    foreach ([['subtotal' => 84, 'order' => $orderA->id], ['subtotal' => 60, 'order' => $orderA->id], ['subtotal' => 100, 'order' => $orderB->id]] as $line) {
        $writer->create($this->appModel, $this->manifest, 'obj_lines_00001', $line, $this->user);
    }
});

it('sums a rollup field across parent records', function () {
    $total = app(RecordQueryService::class)->aggregate(
        $this->appModel,
        ['object_id' => 'obj_orders_0001'],
        'sum',
        'fld_ord_total01',
        $this->manifest,
    );

    // Order A rolls up 84+60=144, order B rolls up 100 → 244 total.
    expect($total)->toBe(244.0);
});

it('averages, mins and maxes a rollup field', function () {
    $svc = app(RecordQueryService::class);
    $query = ['object_id' => 'obj_orders_0001'];

    expect($svc->aggregate($this->appModel, $query, 'avg', 'fld_ord_total01', $this->manifest))->toBe(122.0);
    expect($svc->aggregate($this->appModel, $query, 'min', 'fld_ord_total01', $this->manifest))->toBe(100.0);
    expect($svc->aggregate($this->appModel, $query, 'max', 'fld_ord_total01', $this->manifest))->toBe(144.0);
});

it('sums a formula field across child records', function () {
    $total = app(RecordQueryService::class)->aggregate(
        $this->appModel,
        ['object_id' => 'obj_lines_00001'],
        'sum',
        'fld_lin_tax001',
        $this->manifest,
    );

    // (84+60+100) * 1.16 = 283.04
    expect(round($total, 2))->toBe(283.04);
});

it('validator accepts a metric_grid that aggregates a rollup field', function () {
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_agg_0001',
        'slug' => 'orders_app',
        'name' => 'Orders',
        'version' => 1,
        'objects' => ordersManifest()['objects'],
        'pages' => [[
            'id' => 'pag_dash_00001',
            'slug' => 'dashboard',
            'name' => 'Dashboard',
            'path' => '/',
            'blocks' => [[
                'id' => 'blk_kpis_00001',
                'type' => 'metric_grid',
                'items' => [[
                    'id' => 'itm_total_0001',
                    'label' => 'Ventas',
                    'query' => ['object_id' => 'obj_orders_0001'],
                    'aggregation' => 'sum',
                    'field_id' => 'fld_ord_total01',
                ]],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin_0001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    $result = (new ManifestValidator)->validate($manifest);

    $incompatible = collect($result->errors)->filter(fn ($e) => $e->code === 'incompatible_type');
    expect($incompatible)->toBeEmpty();
});
