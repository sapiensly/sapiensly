<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Asking a table a question in your own words.
 *
 * The compilation is tested where it belongs, against the validator. What has
 * to be true HERE is the part a person experiences: the question lives in the
 * URL so it is shareable and survives a reload, and a phrase that could NOT be
 * turned into a filter says so — because a table that quietly shows every row
 * is showing something that looks like an answer.
 */
function askApp(bool $enabled = true): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $obj = 'obj_'.strtolower((string) Str::ulid());
    $folio = 'fld_'.strtolower((string) Str::ulid());
    $total = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'ask_'.Str::lower(Str::random(5)),
    ]);

    $table = [
        'id' => 'blk_tabla00001',
        'type' => 'table',
        'data_source' => ['object_id' => $obj],
        'columns' => [
            ['id' => 'col_folio00001', 'field_id' => $folio],
            ['id' => 'col_total00001', 'field_id' => $total],
        ],
    ];

    if ($enabled) {
        $table['ask'] = true;
    }

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Pedidos',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $obj,
            'slug' => 'pedidos',
            'name' => 'Pedidos',
            'fields' => [
                ['id' => $folio, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
                ['id' => $total, 'slug' => 'total', 'name' => 'Total', 'type' => 'number'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'pedidos',
            'path' => '/pedidos',
            'name' => 'Pedidos',
            'blocks' => [$table],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    foreach ([['A-1', 1000], ['A-2', 9000]] as [$folioValue, $totalValue]) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => $obj,
            'data' => ['folio' => $folioValue, 'total' => $totalValue],
        ]);
    }

    return $app;
}

it('offers the box only where the manifest asked for it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    visit('/r/'.askApp(enabled: false)->slug.'/pedidos')->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript('document.querySelectorAll("[data-sp-ask]").length', 0);
})->group('browser');

it('offers it in the app language where it did', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = askApp();

    visit("/r/{$app->slug}/pedidos")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript(
            'document.querySelector("[data-sp-ask]").placeholder',
            'Pregunta con tus palabras…',
        );
})->group('browser');

it('puts the question in the URL, so it is shareable and survives a reload', function () {
    // View state belongs in the address bar for the same reason paging does:
    // somebody sends a colleague the list they are looking at, not a
    // description of it.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = askApp();

    $page = visit("/r/{$app->slug}/pedidos")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        (() => {
            const box = document.querySelector('[data-sp-ask]');
            box.value = 'pedidos de más de 5000';
            box.dispatchEvent(new Event('input', { bubbles: true }));
            box.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
            return true;
        })()
    JS);

    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                if (window.location.search.includes('_ask=')) return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    $page->assertNoJavaScriptErrors();
})->group('browser');

it('says a phrase it could not compile instead of showing everything', function () {
    // No model is configured in a test environment, so nothing compiles — which
    // is exactly the case worth asserting. The table must not present the whole
    // list as the answer to a question it did not understand.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = askApp();

    visit("/r/{$app->slug}/pedidos?ta00001_ask=".urlencode('algo incomprensible'))
        ->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('No se pudo convertir esa pregunta en un filtro');
})->group('browser');
