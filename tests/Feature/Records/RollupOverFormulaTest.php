<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;

/**
 * orders ← lines. Each line has a formula (subtotal + IVA); the order rolls up
 * the SUM of that formula across its lines — a rollup over a computed child field.
 *
 * @return array<string, mixed>
 */
function rollupOverFormulaManifest(): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => 'app_rollupfx0001',
        'slug' => 'orders_fx',
        'name' => 'Orders',
        'version' => 1,
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'objects' => [
            [
                'id' => 'obj_orders_fx01',
                'slug' => 'orders',
                'name' => 'Orders',
                'fields' => [
                    ['id' => 'fld_ofx_name001', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                    ['id' => 'fld_ofx_lines01', 'slug' => 'lines', 'name' => 'Lines', 'type' => 'relation', 'cardinality' => 'one_to_many', 'target_object_id' => 'obj_lines_fx001', 'inverse_field_id' => 'fld_lfx_order01'],
                    ['id' => 'fld_ofx_taxtot1', 'slug' => 'tax_total', 'name' => 'Total + IVA', 'type' => 'rollup', 'readonly' => true, 'via_relation_field_id' => 'fld_ofx_lines01', 'aggregator' => 'sum', 'target_field_id' => 'fld_lfx_tax0001'],
                ],
            ],
            [
                'id' => 'obj_lines_fx001',
                'slug' => 'lines',
                'name' => 'Lines',
                'fields' => [
                    ['id' => 'fld_lfx_sub0001', 'slug' => 'subtotal', 'name' => 'Subtotal', 'type' => 'currency', 'currency_code' => 'MXN'],
                    ['id' => 'fld_lfx_order01', 'slug' => 'order', 'name' => 'Order', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_orders_fx01', 'inverse_field_id' => 'fld_ofx_lines01'],
                    ['id' => 'fld_lfx_tax0001', 'slug' => 'subtotal_tax', 'name' => 'Subtotal + IVA', 'type' => 'formula', 'readonly' => true, 'expression' => '{{subtotal * 1.16}}', 'return_type' => 'number'],
                ],
            ],
        ],
    ];
}

it('validator accepts a rollup whose target is a formula field', function () {
    $result = (new ManifestValidator)->validate(rollupOverFormulaManifest());

    expect($result->errors)->toBe([]);
    expect($result->valid)->toBeTrue();
});

it('sums a child formula field through a parent rollup', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => 'orders_fx',
    ]);
    $manifest = rollupOverFormulaManifest();

    $writer = app(RecordWriteService::class);
    $order = $writer->create($app, $manifest, 'obj_orders_fx01', ['name' => 'A'], $user);
    $writer->create($app, $manifest, 'obj_lines_fx001', ['subtotal' => 84, 'order' => $order->id], $user);
    $writer->create($app, $manifest, 'obj_lines_fx001', ['subtotal' => 60, 'order' => $order->id], $user);

    $rows = app(RecordQueryService::class)->query($app, ['object_id' => 'obj_orders_fx01'], $manifest);

    // (84 + 60) * 1.16 = 167.04
    expect(round((float) $rows->first()->data['tax_total'], 2))->toBe(167.04);
});
