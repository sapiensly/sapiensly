<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;

/**
 * categorias ← platillos ← detalle, with lookups of varying numeric-ness:
 *  - detalle.precio_lk     : 1-hop  → platillos.precio   (currency, numeric)
 *  - detalle.cat_margen    : 2-hop  → platillos.cat_margen → categorias.margen (number)
 *  - detalle.cat_nombre    : 2-hop  → platillos.cat_nombre → categorias.nombre (string)
 *
 * @return array<int, array<string, mixed>>
 */
function lookupAggObjects(): array
{
    return [
        [
            'id' => 'obj_cats00001', 'slug' => 'categorias', 'name' => 'Categorias', 'fields' => [
                ['id' => 'fld_cat_nom0001', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
                ['id' => 'fld_cat_mgn0001', 'slug' => 'margen', 'name' => 'Margen', 'type' => 'number'],
            ],
        ],
        [
            'id' => 'obj_plats0001', 'slug' => 'platillos', 'name' => 'Platillos', 'fields' => [
                ['id' => 'fld_pla_nom0001', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
                ['id' => 'fld_pla_pre0001', 'slug' => 'precio', 'name' => 'Precio', 'type' => 'currency', 'currency_code' => 'MXN'],
                ['id' => 'fld_pla_cat0001', 'slug' => 'categoria', 'name' => 'Categoria', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_cats00001'],
                ['id' => 'fld_pla_cmg0001', 'slug' => 'cat_margen', 'name' => 'Cat margen', 'type' => 'lookup', 'readonly' => true, 'via_relation_field_id' => 'fld_pla_cat0001', 'target_field_id' => 'fld_cat_mgn0001'],
                ['id' => 'fld_pla_cnm0001', 'slug' => 'cat_nombre', 'name' => 'Cat nombre', 'type' => 'lookup', 'readonly' => true, 'via_relation_field_id' => 'fld_pla_cat0001', 'target_field_id' => 'fld_cat_nom0001'],
            ],
        ],
        [
            'id' => 'obj_dets00001', 'slug' => 'detalle', 'name' => 'Detalle', 'fields' => [
                ['id' => 'fld_det_pla0001', 'slug' => 'platillo', 'name' => 'Platillo', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_plats0001'],
                ['id' => 'fld_det_pre0001', 'slug' => 'precio_lk', 'name' => 'Precio', 'type' => 'lookup', 'readonly' => true, 'via_relation_field_id' => 'fld_det_pla0001', 'target_field_id' => 'fld_pla_pre0001'],
                ['id' => 'fld_det_cmg0001', 'slug' => 'cat_margen', 'name' => 'Cat margen', 'type' => 'lookup', 'readonly' => true, 'via_relation_field_id' => 'fld_det_pla0001', 'target_field_id' => 'fld_pla_cmg0001'],
                ['id' => 'fld_det_cnm0001', 'slug' => 'cat_nombre', 'name' => 'Cat nombre', 'type' => 'lookup', 'readonly' => true, 'via_relation_field_id' => 'fld_det_pla0001', 'target_field_id' => 'fld_pla_cnm0001'],
            ],
        ],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $kpiItems
 * @return array<string, mixed>
 */
function lookupAggManifest(array $kpiItems): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => 'app_lookupagg01',
        'slug' => 'menu_agg',
        'name' => 'Menu',
        'version' => 1,
        'objects' => lookupAggObjects(),
        'pages' => [[
            'id' => 'pag_dash_000001',
            'slug' => 'dashboard',
            'name' => 'Dashboard',
            'path' => '/',
            'blocks' => [[
                'id' => 'blk_kpis_000001',
                'type' => 'metric_grid',
                'items' => $kpiItems,
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];
}

it('validator accepts summing a single-hop numeric lookup', function () {
    $manifest = lookupAggManifest([[
        'id' => 'itm_precio00001', 'label' => 'Precio', 'aggregation' => 'sum',
        'query' => ['object_id' => 'obj_dets00001'], 'field_id' => 'fld_det_pre0001',
    ]]);

    $result = (new ManifestValidator)->validate($manifest);
    expect($result->errors)->toBe([]);
});

it('validator accepts summing a chained (2-hop) numeric lookup', function () {
    $manifest = lookupAggManifest([[
        'id' => 'itm_margen00001', 'label' => 'Margen', 'aggregation' => 'sum',
        'query' => ['object_id' => 'obj_dets00001'], 'field_id' => 'fld_det_cmg0001',
    ]]);

    $result = (new ManifestValidator)->validate($manifest);
    expect($result->errors)->toBe([]);
});

it('validator still rejects summing a lookup that resolves to a string', function () {
    $manifest = lookupAggManifest([[
        'id' => 'itm_nombre00001', 'label' => 'Nombre', 'aggregation' => 'sum',
        'query' => ['object_id' => 'obj_dets00001'], 'field_id' => 'fld_det_cnm0001',
    ]]);

    $result = (new ManifestValidator)->validate($manifest);
    $incompatible = collect($result->errors)->filter(fn ($e) => $e->code === 'incompatible_type');
    expect($incompatible)->not->toBeEmpty();
});

it('aggregates a lookup field at runtime', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => 'menu_agg',
    ]);
    $manifest = lookupAggManifest([]);

    $writer = app(RecordWriteService::class);
    $cat = $writer->create($app, $manifest, 'obj_cats00001', ['nombre' => 'Tacos', 'margen' => 10], $user);
    $pla = $writer->create($app, $manifest, 'obj_plats0001', ['nombre' => 'Pastor', 'precio' => 28, 'categoria' => $cat->id], $user);
    $writer->create($app, $manifest, 'obj_dets00001', ['platillo' => $pla->id], $user);
    $writer->create($app, $manifest, 'obj_dets00001', ['platillo' => $pla->id], $user);

    $sum = app(RecordQueryService::class)->aggregate(
        $app, ['object_id' => 'obj_dets00001'], 'sum', 'fld_det_pre0001', $manifest,
    );

    // Two lines, each looking up the same $28 price.
    expect($sum)->toBe(56.0);
});
