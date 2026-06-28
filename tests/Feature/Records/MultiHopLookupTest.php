<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;

/**
 * categorias ← platillos ← detalle. A platillo looks up its category name
 * (1 hop); a detalle line looks up the platillo's category-name lookup (2 hops).
 *
 * @return array<string, mixed>
 */
function multiHopManifest(): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => 'app_multihop001',
        'slug' => 'menu',
        'name' => 'Menu',
        'version' => 1,
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'pages' => [],
        'objects' => [
            [
                'id' => 'obj_categorias01',
                'slug' => 'categorias',
                'name' => 'Categorias',
                'fields' => [
                    ['id' => 'fld_cat_nombre01', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
                ],
            ],
            [
                'id' => 'obj_platillos01',
                'slug' => 'platillos',
                'name' => 'Platillos',
                'fields' => [
                    ['id' => 'fld_pla_nombre01', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
                    ['id' => 'fld_pla_categor1', 'slug' => 'categoria', 'name' => 'Categoria', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_categorias01'],
                    ['id' => 'fld_pla_catnom01', 'slug' => 'categoria_nombre', 'name' => 'Categoria (nombre)', 'type' => 'lookup', 'readonly' => true, 'via_relation_field_id' => 'fld_pla_categor1', 'target_field_id' => 'fld_cat_nombre01'],
                ],
            ],
            [
                'id' => 'obj_detalle0001',
                'slug' => 'detalle',
                'name' => 'Detalle',
                'fields' => [
                    ['id' => 'fld_det_platil1', 'slug' => 'platillo', 'name' => 'Platillo', 'type' => 'relation', 'cardinality' => 'many_to_one', 'target_object_id' => 'obj_platillos01'],
                    ['id' => 'fld_det_sub0001', 'slug' => 'subtotal', 'name' => 'Subtotal', 'type' => 'currency', 'currency_code' => 'MXN'],
                    // 2-hop: via platillo → the platillo's own category-name lookup.
                    ['id' => 'fld_det_catnom1', 'slug' => 'categoria_nombre', 'name' => 'Categoria (nombre)', 'type' => 'lookup', 'readonly' => true, 'via_relation_field_id' => 'fld_det_platil1', 'target_field_id' => 'fld_pla_catnom01'],
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
        'slug' => 'menu',
    ]);
    $this->manifest = multiHopManifest();

    $writer = app(RecordWriteService::class);
    $cat = $writer->create($this->appModel, $this->manifest, 'obj_categorias01', ['nombre' => 'Tacos'], $this->user);
    $pla = $writer->create($this->appModel, $this->manifest, 'obj_platillos01', ['nombre' => 'Pastor', 'categoria' => $cat->id], $this->user);
    $writer->create($this->appModel, $this->manifest, 'obj_detalle0001', ['platillo' => $pla->id, 'subtotal' => 28], $this->user);
});

it('resolves a single-hop lookup (product → category name)', function () {
    $rows = app(RecordQueryService::class)->query($this->appModel, ['object_id' => 'obj_platillos01'], $this->manifest);
    expect($rows->first()->data['categoria_nombre'])->toBe('Tacos');
});

it('resolves a multi-hop lookup (line → product → category name)', function () {
    $rows = app(RecordQueryService::class)->query($this->appModel, ['object_id' => 'obj_detalle0001'], $this->manifest);
    expect($rows->first()->data['categoria_nombre'])->toBe('Tacos');
});

it('accepts a chained lookup in the manifest validator', function () {
    $result = (new ManifestValidator)->validate(multiHopManifest());
    expect($result->errors)->toBe([]);
    expect($result->valid)->toBeTrue();
});
