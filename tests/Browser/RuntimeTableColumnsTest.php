<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * A wide list, folded down to what can be scanned.
 *
 * An object with seventeen fields produced a table seventeen columns across:
 * every cell wrapped to three lines and the row stopped reading as one record.
 * Dropping the extra fields was not an option — they are the author's data — so
 * the manifest marks them `hidden_by_default` and the table offers them back
 * through a picker, remembering what the reader chooses.
 *
 * Only a browser can make the round trip: the folding is client state, the
 * choice is written to localStorage, and "does it survive a reload" is the
 * whole point of storing it.
 */
function wideTableApp(): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $objectId = 'obj_'.strtolower((string) Str::ulid());
    $folio = 'fld_'.strtolower((string) Str::ulid());
    $notas = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'wide_table',
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'wide_table',
        'name' => 'Wide table',
        'version' => 1,
        'objects' => [[
            'id' => $objectId,
            'slug' => 'contratos',
            'name' => 'Contrato',
            'fields' => [
                ['id' => $folio, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
                ['id' => $notas, 'slug' => 'notas', 'name' => 'Notas Internas', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'contratos',
            'path' => '/contratos',
            'name' => 'Contratos',
            'blocks' => [[
                'id' => 'blk_table',
                'type' => 'table',
                'data_source' => ['object_id' => $objectId],
                'columns' => [
                    ['id' => 'col_folio00001', 'field_id' => $folio],
                    ['id' => 'col_notas00001', 'field_id' => $notas, 'hidden_by_default' => true],
                ],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    Record::create([
        'app_id' => $app->id,
        'object_definition_id' => $objectId,
        'data' => ['folio' => 'CT-014', 'notas' => 'Renovar en agosto'],
    ]);

    return $app;
}

it('folds a column away and gives it back on request, remembering the choice', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = wideTableApp();

    $page = visit("/r/{$app->slug}/contratos")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        // The row reads: the folio is there, the note the manifest folded is not.
        ->assertSee('CT-014')
        ->assertDontSee('Renovar en agosto')
        // The count is what tells the reader something is missing at all.
        ->assertSee('Columnas 1/2');

    $page->click('Columnas 1/2')
        ->click('Notas Internas')
        ->assertSee('Renovar en agosto')
        // Nothing hidden any more, so the label drops back to the bare word.
        ->assertSee('Columnas')
        ->assertDontSee('Columnas 1/2');

    // The point of persisting it: a reload is not a reason to undo the reader.
    $page->navigate("/r/{$app->slug}/contratos")
        ->assertSee('Renovar en agosto');
});
