<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * A field with no value already showed an em dash rather than vanishing —
 * hiding it would leave the reader unable to tell an empty field from one the
 * object does not have. But the dash was drawn in the same ink as a real value,
 * so a column of them read like content rather than like absence.
 */
it('draws a missing value as absence rather than as content', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    $obj = 'obj_'.strtolower((string) Str::ulid());
    $folio = 'fld_'.strtolower((string) Str::ulid());
    $notas = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id, 'organization_id' => $org->id,
        'slug' => 'blank_'.Str::lower(Str::random(5)),
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0', 'id' => $app->id, 'slug' => $app->slug,
        'name' => 'Blank', 'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [['id' => $obj, 'slug' => 'items', 'name' => 'Items', 'fields' => [
            ['id' => $folio, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
            ['id' => $notas, 'slug' => 'notas', 'name' => 'Notas', 'type' => 'string'],
        ]]],
        'pages' => [['id' => 'pag_'.strtolower((string) Str::ulid()), 'slug' => 'items',
            'path' => '/items', 'name' => 'Items', 'blocks' => [[
                'id' => 'blk_blank0001', 'type' => 'table',
                'data_source' => ['object_id' => $obj],
                'columns' => [
                    ['id' => 'col_folio00003', 'field_id' => $folio],
                    ['id' => 'col_notas00003', 'field_id' => $notas],
                ],
            ]]]],
        'permissions' => [
            'roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ], $user);

    // A record whose second field was never filled in.
    Record::create([
        'app_id' => $app->id, 'object_definition_id' => $obj,
        'data' => ['folio' => 'IT-001'],
    ]);

    visit("/r/{$app->slug}/items")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('IT-001')
        // The mark is there…
        ->assertSee('—')
        // …and it recedes: a lighter ink than the value beside it.
        ->assertScript(<<<'JS'
        (() => {
            const cells = document.querySelectorAll('tbody tr:first-child td');
            const value = getComputedStyle(cells[0]).color;
            const dash = getComputedStyle(cells[1].querySelector('span'), null).color;
            return dash !== value;
        })()
        JS);
})->group('browser');
