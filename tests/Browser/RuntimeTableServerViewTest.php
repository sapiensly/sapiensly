<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * A list bigger than one page.
 *
 * Everything the table does used to happen over the rows it was handed, so a
 * search for a record past the limit answered "no row matches" — which reads as
 * "no such record". Past the ceiling the question now goes to the database, and
 * the answer travels in the URL so a sorted, searched, paged view is a link.
 *
 * Only a browser can test this end to end: the state lives in the query string,
 * the fetch is a partial Inertia reload of `blockData` alone, and the point is
 * that the row comes back.
 */
function bigTableApp(int $rows = 60): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $objectId = 'obj_'.strtolower((string) Str::ulid());
    $folio = 'fld_'.strtolower((string) Str::ulid());
    $importe = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'big_table',
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'big_table',
        'name' => 'Big table',
        'version' => 1,
        'objects' => [[
            'id' => $objectId,
            'slug' => 'contratos',
            'name' => 'Contrato',
            'fields' => [
                ['id' => $folio, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
                ['id' => $importe, 'slug' => 'importe', 'name' => 'Importe', 'type' => 'number'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'contratos',
            'path' => '/contratos',
            'name' => 'Contratos',
            'blocks' => [[
                'id' => 'blk_bigtable01',
                'type' => 'table',
                'data_source' => ['object_id' => $objectId, 'limit' => 10],
                'pagination' => ['page_size' => 10],
                'columns' => [
                    ['id' => 'col_folio00002', 'field_id' => $folio],
                    ['id' => 'col_import00002', 'field_id' => $importe],
                ],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    foreach (range(1, $rows) as $n) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => $objectId,
            'data' => ['folio' => sprintf('CT-%03d', $n), 'importe' => $n * 10],
        ]);
    }

    return $app;
}

it('finds a record that was never on the page, and says how many there are', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = bigTableApp();

    $page = visit("/r/{$app->slug}/contratos")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        // Ten of sixty, and the table says so rather than looking complete.
        ->assertSee('Mostrando 10 de 60');

    // CT-057 is nowhere near the first page. Searching the loaded rows would
    // answer "no row matches"; the database knows better.
    $page->type('input[type=search]', 'CT-057')
        ->assertSee('CT-057')
        ->assertDontSee('Ninguna fila coincide');
});

it('sorts the whole object, not the page it was given', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = bigTableApp();

    // Descending by importe must surface CT-060 — the largest, and absent from
    // the unsorted first page.
    visit("/r/{$app->slug}/contratos")->on()->macbookAir()
        ->click('[data-sp-sort=col_import00002]')
        ->click('[data-sp-sort=col_import00002]')
        ->assertSeeIn('tbody tr:first-child', 'CT-060');
});

it('carries the view in the URL, so it can be sent to someone', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = bigTableApp();

    // The link a colleague would receive: page three, sorted by folio.
    visit("/r/{$app->slug}/contratos?table01_s=folio:asc&table01_p=3")
        ->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('CT-021')
        ->assertDontSee('CT-001');
});
