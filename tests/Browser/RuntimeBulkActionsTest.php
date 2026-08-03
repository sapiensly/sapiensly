<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * One edit, applied to the rows somebody picked.
 *
 * Only a browser can prove this. The selection is client state, the bar appears
 * from it, and the sentence reporting the outcome has to survive the reload the
 * action itself triggers — none of which the server sees. It is also the test
 * that would have caught how this shipped the first time: two identifiers used
 * in the component and never imported, which threw at render and left Vue's
 * patch half-applied, so the state said "3 selected" and the page showed
 * nothing at all. `assertNoJavaScriptErrors` is the point of this file.
 */
function bulkTableApp(): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $objectId = 'obj_'.strtolower((string) Str::ulid());
    $folio = 'fld_'.strtolower((string) Str::ulid());
    $estado = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'bulk_table',
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'bulk_table',
        'name' => 'Bulk table',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $objectId,
            'slug' => 'ordenes',
            'name' => 'Orden',
            'fields' => [
                ['id' => $folio, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
                [
                    'id' => $estado,
                    'slug' => 'estado',
                    'name' => 'Estado',
                    'type' => 'single_select',
                    'options' => [
                        ['id' => 'opt_abierta0001', 'value' => 'abierta', 'label' => 'Abierta'],
                        ['id' => 'opt_cerrada0001', 'value' => 'cerrada', 'label' => 'Cerrada'],
                    ],
                ],
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
                    ['id' => 'col_estado0001', 'field_id' => $estado],
                ],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    foreach (['OS-001', 'OS-002', 'OS-003'] as $ref) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => $objectId,
            'data' => ['folio' => $ref, 'estado' => 'abierta'],
        ]);
    }

    return $app;
}

it('offers the bar only once rows are picked, and edits every one of them', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = bulkTableApp();

    $page = visit("/r/{$app->slug}/ordenes")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('OS-001')
        // A permanently visible toolbar over an idle table is furniture.
        ->assertScript('!!document.querySelector("[data-sp-bulk-bar]")', false);

    $page->script('document.querySelectorAll("[data-sp-select-row]").forEach((b) => b.click())');

    $page->assertScript('!!document.querySelector("[data-sp-bulk-bar]")', true)
        // In the app's own language, not the platform's.
        ->assertSee('3 seleccionados')
        // Named after the column it will write, so "set what?" needs no guess.
        // Read from the option rather than the page: a <select>'s own text is
        // not text the page "sees".
        ->assertScript(
            'document.querySelector("[data-sp-bulk-set]").options[0].textContent.trim()',
            'Poner Estado…',
        );

    // Applying it: the select carries the value, the server does the writing.
    $page->script('(() => {
        const s = document.querySelector("[data-sp-bulk-set]");
        s.value = "cerrada";
        s.dispatchEvent(new Event("change", { bubbles: true }));
    })()');

    $page->assertNoJavaScriptErrors()
        // The report OUTLIVES the selection that caused it — applying clears
        // the picked rows, which hides the bar the message used to live in.
        ->assertSee('3 cambiados, 0 omitidos.')
        ->assertScript('!!document.querySelector("[data-sp-bulk-bar]")', false);

    expect(Record::where('app_id', $app->id)->get()->every(fn (Record $r): bool => $r->data['estado'] === 'cerrada'))
        ->toBeTrue();
});

it('asks before deleting, and says so in the app language', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = bulkTableApp();

    $page = visit("/r/{$app->slug}/ordenes")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script('document.querySelectorAll("[data-sp-select-row]")[0].click()');
    $page->click('Eliminar');

    // A window.confirm would freeze the renderer; this is the runtime's own
    // dialog, which is why the count and the warning can be in Spanish.
    $page->assertSee('¿Eliminar 1 registros?')
        ->assertSee('Se van para todos y no se puede deshacer.');
})->group('browser');

it('never offers the bar to a role that cannot act', function () {
    // The server refuses either way, but a control that is always refused is a
    // control that should not be drawn.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = bulkTableApp();

    visit("/r/{$app->slug}/ordenes?as_role=lector")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript('document.querySelectorAll("[data-sp-select-row]").length', 0);
})->group('browser');
