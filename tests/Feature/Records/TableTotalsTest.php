<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Records\BlockDataResolver;
use Illuminate\Support\Str;

/**
 * The figure at the bottom of a money column.
 *
 * A column of amounts with nothing under it is a column somebody adds up by
 * hand. What makes this worth having rather than dangerous is WHAT it covers:
 * summing the rows that happen to be loaded would answer a different question
 * than the footer appears to be answering, and a wrong total is worse than no
 * total.
 */
function totalsApp(User $owner, array $rows, array $extraFields = [], ?array $filter = null): array
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'tot_'.strtolower(Str::random(6)),
    ]);

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Órdenes',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
        'objects' => [[
            'id' => 'obj_ordenes001',
            'name' => 'Órdenes',
            'slug' => 'ordenes',
            'fields' => array_merge([
                ['id' => 'fld_folio00001', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
                ['id' => 'fld_total00001', 'name' => 'Total', 'slug' => 'total', 'type' => 'currency'],
                ['id' => 'fld_anio000001', 'name' => 'Año', 'slug' => 'anio', 'type' => 'number'],
                ['id' => 'fld_estado0001', 'name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'abierta', 'label' => 'Abierta'],
                    ['value' => 'cerrada', 'label' => 'Cerrada'],
                ]],
            ], $extraFields),
        ]],
        'pages' => [[
            'id' => 'pag_ordenes001',
            'name' => 'Órdenes',
            'slug' => 'ordenes',
            'path' => '/ordenes',
            'blocks' => [array_filter([
                'id' => 'blk_tabla00001',
                'type' => 'table',
                'data_source' => array_filter([
                    'object_id' => 'obj_ordenes001',
                    'limit' => 2, // Deliberately smaller than the data.
                    'filter' => $filter,
                ]),
                'columns' => array_merge([
                    ['id' => 'col_folio00001', 'field_id' => 'fld_folio00001'],
                    ['id' => 'col_total00001', 'field_id' => 'fld_total00001'],
                    ['id' => 'col_anio000001', 'field_id' => 'fld_anio000001'],
                ], array_map(
                    fn (array $f): array => ['id' => 'col_'.substr($f['id'], 4), 'field_id' => $f['id']],
                    $extraFields,
                )),
            ])],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    foreach ($rows as $row) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => 'obj_ordenes001',
            'organization_id' => $app->organization_id,
            'user_id' => $app->user_id,
            'data' => $row,
        ]);
    }

    $data = app(BlockDataResolver::class)->resolve($app, $manifest['pages'][0]['blocks'], $manifest, [
        'current_user' => ['id' => $owner->id, 'email' => $owner->email],
        'params' => [],
    ]);

    return $data['blk_tabla00001'] ?? [];
}

it('sums the whole result, not the page that was sent', function () {
    // The block asks for two rows and there are four. A footer that summed what
    // arrived would say 300 and look exactly as authoritative as 1000.
    $owner = User::factory()->create();

    $payload = totalsApp($owner, [
        ['folio' => 'A', 'total' => 100, 'anio' => 2024, 'estado' => 'abierta'],
        ['folio' => 'B', 'total' => 200, 'anio' => 2024, 'estado' => 'abierta'],
        ['folio' => 'C', 'total' => 300, 'anio' => 2023, 'estado' => 'cerrada'],
        ['folio' => 'D', 'total' => 400, 'anio' => 2023, 'estado' => 'cerrada'],
    ]);

    expect($payload['rows'])->toHaveCount(2)
        ->and($payload['totals']['fld_total00001'])->toBe(1000.0);
});

it('sums money and refuses to sum a number that is not money', function () {
    // "Año: 4,047" is not a fact about anything, and a number column is as
    // likely to hold a year, a mileage or a count of bedrooms.
    $owner = User::factory()->create();

    $payload = totalsApp($owner, [
        ['folio' => 'A', 'total' => 100, 'anio' => 2024],
        ['folio' => 'B', 'total' => 200, 'anio' => 2023],
    ]);

    expect($payload['totals'])->toHaveKey('fld_total00001')
        ->and($payload['totals'])->not->toHaveKey('fld_anio000001');
});

it('follows the filter the table is showing', function () {
    // The whole reason this is computed server-side: a total that ignored the
    // filter would contradict the rows above it.
    $owner = User::factory()->create();

    $payload = totalsApp(
        $owner,
        [
            ['folio' => 'A', 'total' => 100, 'estado' => 'abierta'],
            ['folio' => 'B', 'total' => 200, 'estado' => 'abierta'],
            ['folio' => 'C', 'total' => 300, 'estado' => 'cerrada'],
        ],
        filter: ['op' => 'eq', 'field_id' => 'fld_estado0001', 'value' => 'cerrada'],
    );

    expect($payload['totals']['fld_total00001'])->toBe(300.0);
});

it('leaves a table with no money column without a footer', function () {
    $owner = User::factory()->create();

    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'tot_'.strtolower(Str::random(6)),
    ]);

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Contactos',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_contactos1',
            'name' => 'Contactos',
            'slug' => 'contactos',
            'fields' => [['id' => 'fld_nombre0001', 'name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string']],
        ]],
        'pages' => [[
            'id' => 'pag_contactos',
            'name' => 'Contactos',
            'slug' => 'contactos',
            'path' => '/contactos',
            'blocks' => [[
                'id' => 'blk_tabla00002',
                'type' => 'table',
                'data_source' => ['object_id' => 'obj_contactos1'],
                'columns' => [['id' => 'col_nombre0001', 'field_id' => 'fld_nombre0001']],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_contactos1',
        'organization_id' => $app->organization_id,
        'user_id' => $app->user_id,
        'data' => ['nombre' => 'Ana'],
    ]);

    $payload = app(BlockDataResolver::class)->resolve($app, $manifest['pages'][0]['blocks'], $manifest, [
        'current_user' => ['id' => $owner->id, 'email' => $owner->email],
        'params' => [],
    ])['blk_tabla00002'];

    expect($payload['totals'])->toBe([]);
});
