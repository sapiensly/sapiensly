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
function wideTableApp(int $extraRows = 0): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $objectId = 'obj_'.strtolower((string) Str::ulid());
    $folio = 'fld_'.strtolower((string) Str::ulid());
    $notas = 'fld_'.strtolower((string) Str::ulid());
    $renta = 'fld_'.strtolower((string) Str::ulid());

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
                ['id' => $renta, 'slug' => 'renta', 'name' => 'Renta', 'type' => 'number'],
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
                    ['id' => 'col_renta00001', 'field_id' => $renta],
                    ['id' => 'col_notas00001', 'field_id' => $notas, 'hidden_by_default' => true],
                ],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    Record::create([
        'app_id' => $app->id,
        'object_definition_id' => $objectId,
        'data' => ['folio' => 'CT-014', 'notas' => 'Renovar en agosto', 'renta' => 900],
    ]);

    // Filler so the list is long enough to earn a search box, with rents that
    // expose a lexicographic sort: 90 must not come before 900.
    foreach (range(1, $extraRows) as $n) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => $objectId,
            'data' => [
                'folio' => sprintf('CT-%03d', $n),
                'notas' => 'Dirección '.$n,
                'renta' => $n * 30,
            ],
        ]);
    }

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
        ->assertSee('Columnas 2/3');

    $page->click('Columnas 2/3')
        ->click('Notas Internas')
        ->assertSee('Renovar en agosto')
        // Nothing hidden any more, so the label drops back to the bare word.
        ->assertSee('Columnas')
        ->assertDontSee('Columnas 2/3');

    // The point of persisting it: a reload is not a reason to undo the reader.
    $page->navigate("/r/{$app->slug}/contratos")
        ->assertSee('Renovar en agosto');
});

it('sorts by a column as a number, not as text', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Rents of 30, 60 … 300, plus the 900 on CT-014. Sorted as text, "900"
    // lands between "60" and "90" — the classic wrong answer.
    $app = wideTableApp(extraRows: 10);

    visit("/r/{$app->slug}/contratos")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        // Ascending: the cheapest rent leads, the 900 is last.
        ->click('[data-sp-sort=col_renta00001]')
        ->assertSeeIn('tbody tr:first-child', 'CT-001')
        ->assertSeeIn('tbody tr:last-child', 'CT-014')
        // A second click turns it around. Compared as text, "900" would sit
        // between "60" and "90" and CT-014 would not lead.
        ->click('[data-sp-sort=col_renta00001]')
        ->assertSeeIn('tbody tr:first-child', 'CT-014')
        ->assertSeeIn('tbody tr:last-child', 'CT-001')
        // A third clears it, so "the order the author chose" stays reachable.
        ->click('[data-sp-sort=col_renta00001]')
        ->assertSeeIn('tbody tr:first-child', 'CT-014');
});

it('searches what the cell shows, accents and all, and says so when nothing matches', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = wideTableApp(extraRows: 10);

    $page = visit("/r/{$app->slug}/contratos")->on()->macbookAir()
        ->assertSee('CT-001')
        ->assertSee('CT-014');

    // Typed without the accent, matching "Dirección 7" — the fold is the whole
    // point in Spanish. The note lives in a folded column and still matches:
    // switching it on is what the reader would do next.
    $page->click('Columnas 2/3')->click('Notas Internas')
        ->type('input[type=search]', 'direccion 7')
        ->assertSee('CT-007')
        ->assertDontSee('CT-014');

    // An empty object and a search that found nothing are different facts.
    $page->fill('input[type=search]', 'no existe')
        ->assertSee('Ninguna fila coincide con')
        ->assertDontSee('No records yet.');
});

it('keeps a row to one line and lines its numbers up', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = wideTableApp(extraRows: 4);

    // A row used to wrap every long cell to three lines, so a list of six
    // records filled the screen and stopped being scannable. Text truncates
    // now, and the quantities share an alignment so a column of money reads
    // as a column.
    visit("/r/{$app->slug}/contratos")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript(
            "getComputedStyle(document.querySelector('tbody td:last-child')).textAlign === 'right'"
        )
        ->assertScript(
            "getComputedStyle(document.querySelector('tbody td:first-child')).textOverflow === 'ellipsis'"
        );
})->group('browser');
