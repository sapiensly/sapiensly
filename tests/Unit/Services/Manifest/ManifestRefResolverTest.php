<?php

use App\Services\Manifest\ManifestRefResolver;

/**
 * The resolver exists because `unresolved_ref` was 18 of 30 rejected patches in
 * the failure ledger — the single most expensive thing a build does wrong. Each
 * case below is one of those rejections, quoted from what the model actually
 * wrote.
 */
function refManifest(): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => 'app_01kzaeq206ma38yxvtp0ewy24x',
        'slug' => 'campo',
        'name' => 'Campo',
        'version' => 1,
        'objects' => [
            [
                'id' => 'obj_clientes0000000000000000',
                'slug' => 'clientes',
                'name' => 'Clientes',
                'primary_display_field_id' => 'fld_clinombre000000000000000',
                'fields' => [
                    ['id' => 'fld_clinombre000000000000000', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
                ],
            ],
            [
                'id' => 'obj_refacciones00000000000',
                'slug' => 'refacciones',
                'name' => 'Refacciones',
                'primary_display_field_id' => 'fld_refsku0000000000000000',
                'fields' => [
                    ['id' => 'fld_refsku0000000000000000', 'slug' => 'sku', 'name' => 'SKU', 'type' => 'string'],
                    ['id' => 'fld_refprecio00000000000000', 'slug' => 'precio', 'name' => 'Precio', 'type' => 'currency'],
                ],
            ],
            [
                'id' => 'obj_lineas000000000000000',
                'slug' => 'lineas_refaccion',
                'name' => 'Líneas',
                'primary_display_field_id' => 'fld_lincantidad000000000000',
                'fields' => [
                    ['id' => 'fld_lincantidad000000000000', 'slug' => 'cantidad', 'name' => 'Cantidad', 'type' => 'number'],
                    ['id' => 'fld_linrefaccion00000000000', 'slug' => 'refaccion', 'name' => 'refaccion', 'type' => 'relation',
                        'cardinality' => 'many_to_one', 'target_object_id' => 'obj_refacciones00000000000'],
                ],
            ],
        ],
        'pages' => [
            ['id' => 'pag_refacciones0000000000', 'slug' => 'refacciones', 'name' => 'Refacciones', 'path' => '/refacciones', 'blocks' => []],
        ],
        'permissions' => [
            'roles' => [
                ['id' => 'rol_tecnico00000000000000', 'slug' => 'tecnico', 'name' => 'Técnico', 'is_default' => true],
            ],
        ],
    ];
}

it('resolves a column field written as a slug against its block object', function () {
    // The live rejection: "column field_id 'fld_…qvz' does not belong to object
    // 'obj_…akc5'" — an id the model assembled from a neighbouring one.
    $m = refManifest();
    $m['pages'][0]['blocks'] = [[
        'id' => 'blk_tabla000000000000000000',
        'type' => 'table',
        'data_source' => ['object_id' => 'refacciones'],
        'columns' => [
            ['id' => 'col_1000000000000000000000', 'field_id' => 'sku'],
            ['id' => 'col_2000000000000000000000', 'field_id' => 'precio'],
        ],
    ]];

    $r = ManifestRefResolver::resolve($m);
    $block = $r['pages'][0]['blocks'][0];

    expect($block['data_source']['object_id'])->toBe('obj_refacciones00000000000')
        ->and($block['columns'][0]['field_id'])->toBe('fld_refsku0000000000000000')
        ->and($block['columns'][1]['field_id'])->toBe('fld_refprecio00000000000000');
});

it('scopes a field to its own block, so one slug means different things on one page', function () {
    // `nombre` under a clientes block and `sku` under a refacciones block. The
    // scope is what the model does not have and the resolver does.
    $m = refManifest();
    $m['pages'][0]['blocks'] = [
        ['id' => 'blk_a000000000000000000000', 'type' => 'table', 'data_source' => ['object_id' => 'clientes'],
            'columns' => [['id' => 'col_a000000000000000000000', 'field_id' => 'nombre']]],
        ['id' => 'blk_b000000000000000000000', 'type' => 'table', 'data_source' => ['object_id' => 'refacciones'],
            'columns' => [['id' => 'col_b000000000000000000000', 'field_id' => 'sku']]],
    ];

    $r = ManifestRefResolver::resolve($m);

    expect($r['pages'][0]['blocks'][0]['columns'][0]['field_id'])->toBe('fld_clinombre000000000000000')
        ->and($r['pages'][0]['blocks'][1]['columns'][0]['field_id'])->toBe('fld_refsku0000000000000000');
});

it('resolves a rollup target against the RELATED object, not the one holding it', function () {
    // The one place the enclosing scope is the wrong answer: `precio` lives on
    // refacciones, reached through the `refaccion` relation.
    $m = refManifest();
    $m['objects'][2]['fields'][] = [
        'id' => 'fld_lintotal00000000000000',
        'slug' => 'total', 'name' => 'Total', 'type' => 'rollup', 'readonly' => true,
        'aggregator' => 'sum',
        'via_relation_field_id' => 'refaccion',
        'target_field_id' => 'precio',
    ];

    $field = ManifestRefResolver::resolve($m)['objects'][2]['fields'][2];

    expect($field['via_relation_field_id'])->toBe('fld_linrefaccion00000000000')
        ->and($field['target_field_id'])->toBe('fld_refprecio00000000000000');
});

it('resolves the role, page and hidden-field slugs a permissions patch is made of', function () {
    // Three separate live rejections landed in /permissions: an unknown role_id,
    // three page_ids matching no page, and a hidden field_id belonging to
    // another object.
    $m = refManifest();
    $m['permissions']['object_policies'] = [[
        'role_id' => 'tecnico',
        'object_id' => 'refacciones',
        'actions' => ['read'],
        'field_restrictions' => ['hidden' => ['precio']],
    ]];
    $m['permissions']['page_policies'] = [[
        'role_id' => 'tecnico',
        'page_id' => 'refacciones',
        'can_view' => false,
    ]];

    $p = ManifestRefResolver::resolve($m)['permissions'];

    expect($p['object_policies'][0]['role_id'])->toBe('rol_tecnico00000000000000')
        ->and($p['object_policies'][0]['object_id'])->toBe('obj_refacciones00000000000')
        ->and($p['object_policies'][0]['field_restrictions']['hidden'][0])->toBe('fld_refprecio00000000000000')
        ->and($p['page_policies'][0]['page_id'])->toBe('pag_refacciones0000000000');
});

it('never re-points a reference that is already a real id', function () {
    // Ids win. A page slug and an object slug are both 'refacciones' here, and
    // the id pattern cannot tell an id from a slug ('fecha_inicio_programada'
    // satisfies it) — so existence, not shape, is the test.
    $m = refManifest();
    $m['pages'][0]['blocks'] = [[
        'id' => 'blk_tabla000000000000000000',
        'type' => 'table',
        'data_source' => ['object_id' => 'obj_clientes0000000000000000'],
        'columns' => [['id' => 'col_1000000000000000000000', 'field_id' => 'fld_clinombre000000000000000']],
    ]];

    $r = ManifestRefResolver::resolve($m);

    expect($r['pages'][0]['blocks'][0]['data_source']['object_id'])->toBe('obj_clientes0000000000000000')
        ->and($r['pages'][0]['blocks'][0]['columns'][0]['field_id'])->toBe('fld_clinombre000000000000000');
});

it('leaves a name that resolves to nothing exactly as written', function () {
    // The validator must still reject it, with the message it always gave. A
    // resolver that guessed here would turn a loud failure into a silent
    // mis-wiring.
    $m = refManifest();
    $m['pages'][0]['blocks'] = [[
        'id' => 'blk_tabla000000000000000000',
        'type' => 'table',
        'data_source' => ['object_id' => 'refacciones'],
        'columns' => [['id' => 'col_1000000000000000000000', 'field_id' => 'no_existe']],
    ]];

    expect(ManifestRefResolver::resolve($m)['pages'][0]['blocks'][0]['columns'][0]['field_id'])
        ->toBe('no_existe');
});

it('leaves a manifest with nothing to resolve against untouched', function () {
    $empty = ['schema_version' => '1.0.0', 'id' => 'app_x00000000000000000000', 'slug' => 'x', 'name' => 'X', 'version' => 1];

    expect(ManifestRefResolver::resolve($empty))->toBe($empty);
});
