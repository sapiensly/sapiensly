<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * A code somebody can scan off paper.
 *
 * The other direction from the scanner field: reading one is a camera problem,
 * DRAWING one is an encoding problem — and an app that could read barcodes but
 * never print them could not label the thing it was about to be asked to find.
 *
 * One per row, from the same data pipeline as every other block: a page
 * filtered to one record prints one label, and the same page unfiltered prints
 * a sheet of them, which is what label stock is.
 */
function barcodeBlockApp(string $symbology = 'code128', array $skus = ['7501234567890']): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $obj = 'obj_'.strtolower((string) Str::ulid());
    $sku = 'fld_'.strtolower((string) Str::ulid());
    $nombre = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'lbl_'.Str::lower(Str::random(5)),
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Etiquetas',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $obj,
            'slug' => 'articulos',
            'name' => 'Artículos',
            'fields' => [
                ['id' => $sku, 'slug' => 'sku', 'name' => 'SKU', 'type' => 'string'],
                ['id' => $nombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            // A page with no codes on it, to prove the encoders stay unloaded.
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'inicio',
            'path' => '/inicio',
            'name' => 'Inicio',
            'blocks' => [[
                'id' => 'blk_saludo00001',
                'type' => 'heading',
                'content' => 'Artículos',
            ]],
        ], [
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'etiquetas',
            'path' => '/etiquetas',
            'name' => 'Etiquetas',
            'blocks' => [
                [
                    'id' => 'blk_codigos0001',
                    'type' => 'barcode',
                    'data_source' => ['object_id' => $obj],
                    'field_id' => $sku,
                    'caption_field_id' => $nombre,
                    'symbology' => $symbology,
                    'per_row' => 2,
                ],
                [
                    'id' => 'blk_imprimir001',
                    'type' => 'button',
                    'label' => 'Imprimir etiquetas',
                    'on_click' => [[
                        'type' => 'download_pdf',
                        'page_slug' => 'etiquetas',
                        'paper' => 'label_4x6',
                    ]],
                ],
            ],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    foreach ($skus as $i => $code) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => $obj,
            'data' => ['sku' => $code, 'nombre' => 'Caja '.($i + 1)],
        ]);
    }

    return $app;
}

it('draws a scannable code, not just the number', function () {
    // The whole point. A label with a human-readable SKU and no bars is a
    // sticker, and somebody still has to type the code in.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeBlockApp();

    $page = visit("/r/{$app->slug}/etiquetas")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    // Encoding happens in the browser and the encoder is loaded on demand.
    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                if (document.querySelector('[data-sp-barcode] svg rect')) return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    $page->assertNoJavaScriptErrors()
        // The caption is what a human reads off the same label.
        ->assertSee('Caja 1');
})->group('browser');

it('prints one label per record', function () {
    // A page filtered to one record makes one; unfiltered it makes a sheet.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeBlockApp(skus: ['A-1', 'A-2', 'A-3']);

    $page = visit("/r/{$app->slug}/etiquetas")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                if (document.querySelectorAll('[data-sp-barcode] svg').length === 3) {
                    return true;
                }
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);
})->group('browser');

it('draws a QR when asked for one', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeBlockApp(symbology: 'qr', skus: ['https://ejemplo.test/activo/42']);

    $page = visit("/r/{$app->slug}/etiquetas")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    // A QR comes back as an image rather than an SVG — the encoders are
    // different libraries, loaded separately and only the one in use.
    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                const img = document.querySelector('[data-sp-barcode] img');
                if (img && img.src.startsWith('data:image/png')) return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);
})->group('browser');

it('says which value it could not encode rather than printing a gap', function () {
    // An EAN-13 needs 13 digits. A blank space on a label is something
    // somebody prints a thousand of before anybody notices.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeBlockApp(symbology: 'ean13', skus: ['NO-ES-UN-EAN']);

    $page = visit("/r/{$app->slug}/etiquetas")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                if (document.body.innerText.includes('NO-ES-UN-EAN ✕')) return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);
})->group('browser');

it('does not make a page without codes pay for an encoder', function () {
    // The encoders are loaded on demand and most pages carry neither — and a
    // lazy import is exactly the kind of thing that quietly stops being lazy.
    //
    // Asserted against the chunk names the build actually emits. The first
    // version of this test filtered on "qrcode" and passed for the wrong
    // reason: that library's entry point is called browser.js, so the filter
    // matched nothing whether it loaded or not.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeBlockApp();

    // A page of this app with no barcode block on it.
    visit("/r/{$app->slug}/inicio")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript(<<<'JS'
            performance
                .getEntriesByType('resource')
                .filter((r) => /jsbarcode|BlockBarcode/i.test(r.name)).length
        JS, 0);
})->group('browser');

it('loads only the encoder the page actually needs', function () {
    // A Code 128 page must not download a QR encoder. Matched loosely on
    // purpose: this suite runs against the dev server, where a module keeps its
    // package name, while a built chunk is renamed and hashed. The production
    // split is verified in the build output; what is asserted here is that the
    // import stays conditional at all.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeBlockApp();

    $page = visit("/r/{$app->slug}/etiquetas")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                if (document.querySelector('[data-sp-barcode] svg rect')) return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    $page->assertScript(<<<'JS'
        performance
            .getEntriesByType('resource')
            .filter((r) => /qrcode/i.test(r.name)).length
    JS, 0);
})->group('browser');
