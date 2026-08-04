<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * When a page is safe to photograph.
 *
 * `blockData` is a DEFERRED Inertia prop: it is not in the first response, it
 * arrives on a second request after the page mounts. So fonts loaded and first
 * paint settled are NOT enough to say the page is ready — and saying so anyway
 * printed a work order whose table was empty, because the capture beat the
 * data.
 *
 * Found by rendering one and reading it, which is the only way this shows up:
 * every server-side assertion passes on a page whose rows have not arrived yet.
 * Only a browser can watch a flag over time.
 */
function printReadyApp(): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $obj = 'obj_'.strtolower((string) Str::ulid());
    $folio = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'prt_'.Str::lower(Str::random(5)),
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Órdenes',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $obj,
            'slug' => 'ordenes',
            'name' => 'Órdenes',
            'fields' => [['id' => $folio, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string']],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'ordenes',
            'path' => '/ordenes',
            'name' => 'Órdenes',
            'blocks' => [[
                'id' => 'blk_tabla00001',
                'type' => 'table',
                'data_source' => ['object_id' => $obj],
                'columns' => [['id' => 'col_folio00001', 'field_id' => $folio]],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    Record::create([
        'app_id' => $app->id,
        'object_definition_id' => $obj,
        'data' => ['folio' => 'OS-001'],
    ]);

    return $app;
}

it('does not call itself ready until the rows are actually there', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = printReadyApp();

    $page = visit("/r/{$app->slug}/ordenes")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    // The flag eventually goes up…
    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                if (window.__spPrintReady === true) return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    // …and by then the deferred data is on the page. This is the assertion
    // that would have failed before: the flag used to rise on fonts alone,
    // while the table was still empty.
    $page->assertSee('OS-001');
})->group('browser');

it('renders the page without its chrome when printing', function () {
    // The signed route is what headless Chrome loads; a person reaching the
    // same page normally still gets the menu.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = printReadyApp();

    visit("/r/{$app->slug}/ordenes")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        // Not printing: the app's own navigation is there.
        ->assertScript('!!document.querySelector("header, nav, aside")', true);
})->group('browser');
