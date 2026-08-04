<?php

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * A code read off a label instead of typed.
 *
 * An option on `string`, not a type of its own: the VALUE is a string either
 * way, so filters, tables, exports and validation keep working untouched. Same
 * argument as the camera on `file`, and it keeps the change at four files
 * rather than twenty-three.
 *
 * Two ways in, because a warehouse has two. The camera is for a phone; a
 * handheld gun is a KEYBOARD that types the code and sends Enter, which is what
 * the people who scan all day actually hold. The box still takes a typed code,
 * so a dead camera, a desktop or a damaged label is never a dead end.
 */
function barcodeApp(bool $withScan = true): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $obj = 'obj_'.strtolower((string) Str::ulid());
    $sku = 'fld_'.strtolower((string) Str::ulid());
    $nombre = 'fld_'.strtolower((string) Str::ulid());

    $skuField = ['id' => $sku, 'slug' => 'sku', 'name' => 'SKU', 'type' => 'string'];
    if ($withScan) {
        $skuField['capture'] = 'barcode';
    }

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'bcd_'.Str::lower(Str::random(5)),
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Inventario',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $obj,
            'slug' => 'articulos',
            'name' => 'Artículos',
            'fields' => [
                $skuField,
                // A plain text field beside it: the option must change one
                // control and not every text box in every app.
                ['id' => $nombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'nuevo',
            'path' => '/nuevo',
            'name' => 'Nuevo artículo',
            'blocks' => [[
                'id' => 'blk_form0001',
                'type' => 'form',
                'object_id' => $obj,
                'mode' => 'create',
                'fields' => [['field_id' => $sku], ['field_id' => $nombre]],
                'submit_label' => 'Guardar',
                'on_submit' => [['type' => 'create_record', 'object_id' => $obj, 'values' => ['sku' => '{{form.sku}}']]],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    return $app;
}

it('offers a way to scan, beside a box that still takes typing', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeApp();

    visit("/r/{$app->slug}/nuevo")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Escanear')
        // One scan button, for one scannable field.
        ->assertScript('document.querySelectorAll("[data-sp-scan-open]").length', 1)
        // And the text box is still there: the camera is an extra way in,
        // never the only one.
        ->assertScript('document.querySelectorAll("input[type=text]").length', 2);
})->group('browser');

it('leaves ordinary text fields alone', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeApp(withScan: false);

    visit("/r/{$app->slug}/nuevo")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertDontSee('Escanear')
        ->assertScript('document.querySelectorAll("[data-sp-scan-open]").length', 0);
})->group('browser');

it('recognises a handheld gun by how fast it types', function () {
    // A person cannot type six characters in under 30ms each. A gun does
    // nothing else — and swallowing its Enter is what stops a half-filled form
    // submitting itself between two scans.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeApp();

    $page = visit("/r/{$app->slug}/nuevo")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        (() => {
            const input = document.querySelector('input[type=text]');
            input.focus();
            // The burst: fast keydowns, then the Enter a gun always sends.
            for (const ch of '7501234567890') {
                input.dispatchEvent(new KeyboardEvent('keydown', { key: ch, bubbles: true }));
            }
            input.value = '7501234567890';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
            return true;
        })()
    JS);

    $page->assertNoJavaScriptErrors()
        // It says so, so the operator can look away and keep scanning.
        ->assertSee('Escaneado')
        // And the form did NOT submit: still on the page, box still filled.
        ->assertScript('document.querySelector("input[type=text]").value', '7501234567890');
})->group('browser');

it('does not mistake a person typing for a scanner', function () {
    // The whole rule is the speed. A human filling the box by hand must not
    // have their Enter swallowed — that would break submitting the form.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeApp();

    $page = visit("/r/{$app->slug}/nuevo")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        (async () => {
            const input = document.querySelector('input[type=text]');
            input.focus();
            for (const ch of 'ABC123') {
                input.dispatchEvent(new KeyboardEvent('keydown', { key: ch, bubbles: true }));
                await new Promise((r) => setTimeout(r, 60)); // human speed
            }
            input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
            return true;
        })()
    JS);

    $page->assertNoJavaScriptErrors()
        ->assertDontSee('Escaneado');
})->group('browser');

it('opens the camera sheet, and closes it again', function () {
    // Headless Chrome has no camera, so what this proves is the sheet and the
    // way out of it — and that a refused camera ends somewhere a person can
    // still type, which is the rail every capture in this wave follows.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = barcodeApp();

    $page = visit("/r/{$app->slug}/nuevo")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script('document.querySelector("[data-sp-scan-open]").click()');

    $page->assertScript('!!document.querySelector("[data-sp-scanner]")', true)
        ->assertSee('Apunta la cámara al código');

    $page->script('document.querySelector("[data-sp-scanner-close]").click()');

    $page->assertNoJavaScriptErrors()
        ->assertScript('!!document.querySelector("[data-sp-scanner]")', false)
        // Back to a field somebody can still fill by hand.
        ->assertScript('document.querySelectorAll("input[type=text]").length', 2);
})->group('browser');
