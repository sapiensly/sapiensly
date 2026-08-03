<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * The trash, from the side somebody actually uses it.
 *
 * The recovery had to live where the deletion happened. Putting it in the
 * builder would have repeated the mistake app versions made for months: a
 * history nobody can reach is a backup nobody has, and the person who just
 * deleted twelve orders by accident is looking at the orders list, not at the
 * builder.
 *
 * Only a browser can show that: the way in appears from a count the server
 * sends, the mode lives in the URL, and what the bar offers changes once you
 * are inside.
 */
function trashRuntimeApp(): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $objectId = 'obj_'.strtolower((string) Str::ulid());
    $folio = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'trash_table',
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'trash_table',
        'name' => 'Trash table',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $objectId,
            'slug' => 'ordenes',
            'name' => 'Orden',
            'fields' => [
                ['id' => $folio, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'ordenes',
            'path' => '/ordenes',
            'name' => 'Órdenes',
            'blocks' => [[
                'id' => 'blk_table',
                'type' => 'table',
                'data_source' => ['object_id' => $objectId],
                'columns' => [
                    ['id' => 'col_folio00001', 'field_id' => $folio],
                    [
                        'id' => 'col_borrar0001',
                        'type' => 'action',
                        'label' => 'Quitar',
                        'variant' => 'danger',
                        'on_click' => [],
                    ],
                ],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    foreach (['OS-001', 'OS-002', 'OS-003'] as $ref) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => $objectId,
            'data' => ['folio' => $ref],
        ]);
    }

    return $app;
}

it('offers no way into an empty trash', function () {
    // "Papelera (0)" is a door into an empty room, and the reader has to try it
    // to find that out.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = trashRuntimeApp();

    visit("/r/{$app->slug}/ordenes")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('OS-001')
        ->assertScript('!!document.querySelector("[data-sp-trash-open]")', false);
});

it('takes a deleted row into the trash and brings it back', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = trashRuntimeApp();

    $page = visit("/r/{$app->slug}/ordenes")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    // Delete one, through the bar that put it there. Both the bar's button and
    // the dialog's say "Eliminar", so they are addressed by their markers
    // rather than by text — a click by label matches two things at once.
    $page->script('document.querySelectorAll("[data-sp-select-row]")[0].click()');
    $page->script('document.querySelector("[data-sp-bulk-delete]").click()');
    $page->assertSee('¿Eliminar 1 registros?');
    $page->script('document.querySelector("[data-sp-confirm=\'yes\']").click()');

    $page->assertNoJavaScriptErrors()
        ->assertDontSee('OS-001')
        // The way in appears, counting what is behind it.
        ->assertSee('Papelera (1)');

    $page->script('document.querySelector("[data-sp-trash-open]").click()');

    $page->assertNoJavaScriptErrors()
        // Standing notice: a short list must not read as data having vanished.
        ->assertSee('Estás en la papelera')
        ->assertSee('OS-001')
        ->assertDontSee('OS-002')
        // A deleted row's own actions lead nowhere and are not drawn.
        ->assertScript('!!document.querySelector("[data-sp-row-action]")', false);

    // Back out, over the same endpoint the delete used.
    $page->script('document.querySelectorAll("[data-sp-select-row]")[0].click()');
    $page->script('document.querySelector("[data-sp-bulk-restore]").click()');

    $page->assertNoJavaScriptErrors()
        ->assertSee('1 restaurados, 0 omitidos.');

    expect(Record::where('app_id', $app->id)->count())->toBe(3);
})->group('browser');

it('says the trash is empty in the app language, not the platform’s', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = trashRuntimeApp();

    visit("/r/{$app->slug}/ordenes?trash=blk_table")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Aquí no se ha eliminado nada.');
})->group('browser');

/**
 * An app where deleting one thing costs something somewhere else: orders that
 * name a customer.
 */
function trashRelationRuntimeApp(): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'trash_rel',
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'trash_rel',
        'name' => 'Trash rel',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [
            [
                'id' => 'obj_cliente00001',
                'slug' => 'clientes',
                'name' => 'Clientes',
                'primary_display_field_id' => 'fld_nombre00001',
                'fields' => [
                    ['id' => 'fld_nombre00001', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
                ],
            ],
            [
                'id' => 'obj_ordenes0001',
                'slug' => 'ordenes',
                'name' => 'Órdenes',
                'fields' => [
                    ['id' => 'fld_folio00001', 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
                    [
                        'id' => 'fld_cliente0001', 'slug' => 'cliente', 'name' => 'Cliente',
                        'type' => 'relation', 'cardinality' => 'many_to_one',
                        'target_object_id' => 'obj_cliente00001',
                    ],
                ],
            ],
        ],
        'pages' => [
            [
                'id' => 'pag_clientes0001',
                'slug' => 'clientes',
                'path' => '/clientes',
                'name' => 'Clientes',
                'blocks' => [[
                    'id' => 'blk_clientes',
                    'type' => 'table',
                    'data_source' => ['object_id' => 'obj_cliente00001'],
                    'columns' => [['id' => 'col_nombre00001', 'field_id' => 'fld_nombre00001']],
                ]],
            ],
            [
                'id' => 'pag_ordenes0001',
                'slug' => 'ordenes',
                'path' => '/ordenes',
                'name' => 'Órdenes',
                'blocks' => [[
                    'id' => 'blk_ordenes',
                    'type' => 'table',
                    'data_source' => ['object_id' => 'obj_ordenes0001'],
                    'columns' => [
                        ['id' => 'col_folio00001', 'field_id' => 'fld_folio00001'],
                        ['id' => 'col_cliente0001', 'field_id' => 'fld_cliente0001'],
                    ],
                ]],
            ],
        ],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    $cliente = Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_cliente00001',
        'data' => ['nombre' => 'Acme'],
    ]);

    foreach (['OS-001', 'OS-002', 'OS-003'] as $folio) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => 'obj_ordenes0001',
            'data' => ['folio' => $folio, 'cliente' => $cliente->id],
        ]);
    }

    return $app;
}

it('warns what points at a record before agreeing to delete it', function () {
    // Agreeing to "this cannot be undone" was never the whole cost: the three
    // orders naming this customer keep an id and lose the name.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = trashRelationRuntimeApp();

    $page = visit("/r/{$app->slug}/clientes")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Acme');

    $page->script('document.querySelectorAll("[data-sp-select-row]")[0].click()');
    $page->script('document.querySelector("[data-sp-bulk-delete]").click()');

    $page->assertNoJavaScriptErrors()
        ->assertSee('¿Eliminar 1 registros?')
        ->assertSee('3 × Órdenes');
})->group('browser');

it('says a relation points at something deleted rather than showing a gap', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = trashRelationRuntimeApp();

    // Delete the customer the three orders name.
    $page = visit("/r/{$app->slug}/clientes")->on()->macbookAir()
        ->assertNoJavaScriptErrors();
    $page->script('document.querySelectorAll("[data-sp-select-row]")[0].click()');
    $page->script('document.querySelector("[data-sp-bulk-delete]").click()');
    $page->assertSee('¿Eliminar 1 registros?');
    $page->script('document.querySelector("[data-sp-confirm=\'yes\']").click()');
    $page->assertSee('1 eliminados, 0 omitidos.');

    // The orders still read as orders, and say what became of their customer.
    visit("/r/{$app->slug}/ordenes")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('OS-001')
        ->assertSee('Acme (eliminado)');
})->group('browser');
