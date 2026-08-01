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

/**
 * The childless parent — the case every test above happens to avoid by giving
 * each order at least one line, and the reason this went unnoticed: the empty
 * branch handed the aggregator a base collection where it type-hints Eloquent's,
 * so resolution threw, the exception was swallowed as a warning, and the field
 * was left unset. A count that should read 0 rendered as an em dash on exactly
 * the records where "none yet" is the thing you want to see.
 */
it('rolls a childless parent up to zero, not to nothing', function () {
    $writer = app(RecordWriteService::class);
    $empty = $writer->create($this->appModel, $this->manifest, 'obj_orders_0001', ['name' => 'Sin lineas'], $this->user);

    $rows = app(RecordQueryService::class)->query(
        $this->appModel,
        ['object_id' => 'obj_orders_0001'],
        $this->manifest,
    );

    $row = $rows->firstWhere('id', $empty->id);

    expect($row)->not->toBeNull()
        ->and($row->data)->toHaveKey('total')
        // Numeric, not typed: an empty sum folds to int 0 where a populated one
        // folds to float. The claim here is "zero", not "zero of that PHP type".
        ->and($row->data['total'])->toEqual(0);
});

it('keeps a childless parent out of the way of the others', function () {
    // The zero must not disturb the aggregate the populated parents produce.
    $writer = app(RecordWriteService::class);
    $writer->create($this->appModel, $this->manifest, 'obj_orders_0001', ['name' => 'Sin lineas'], $this->user);

    $svc = app(RecordQueryService::class);
    $query = ['object_id' => 'obj_orders_0001'];

    expect($svc->aggregate($this->appModel, $query, 'sum', 'fld_ord_total01', $this->manifest))->toBe(244.0)
        ->and($svc->aggregate($this->appModel, $query, 'min', 'fld_ord_total01', $this->manifest))->toBe(0.0);
});
