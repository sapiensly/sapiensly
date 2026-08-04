<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Where something is, captured by somebody who chose to.
 *
 * The button is a convenience, never the only way in: the two coordinate boxes
 * are always there, so the field works on a roof with GPS, in an office with a
 * refused permission, and off a survey where there was never a device at all.
 * That is the rail every capture in this wave follows, and here it is the whole
 * design rather than a fallback bolted on.
 */
function geoRuntimeApp(): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $obj = 'obj_'.strtolower((string) Str::ulid());
    $sitio = 'fld_'.strtolower((string) Str::ulid());
    $donde = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'geo_'.Str::lower(Str::random(5)),
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Visitas',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $obj,
            'slug' => 'visitas',
            'name' => 'Visitas',
            'fields' => [
                ['id' => $sitio, 'slug' => 'sitio', 'name' => 'Sitio', 'type' => 'string'],
                ['id' => $donde, 'slug' => 'donde', 'name' => 'Dónde', 'type' => 'geo'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'nueva',
            'path' => '/nueva',
            'name' => 'Nueva visita',
            'blocks' => [[
                'id' => 'blk_form0001',
                'type' => 'form',
                'object_id' => $obj,
                'mode' => 'create',
                'fields' => [['field_id' => $sitio], ['field_id' => $donde]],
                'submit_label' => 'Guardar',
                'on_submit' => [[
                    'type' => 'create_record',
                    'object_id' => $obj,
                    'values' => ['sitio' => '{{form.sitio}}', 'donde' => '{{form.donde}}'],
                ]],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    return $app;
}

it('offers the boxes and the button together, in the app language', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = geoRuntimeApp();

    visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Usar mi ubicación')
        ->assertScript('!!document.querySelector("[data-sp-geo-lat]")', true)
        ->assertScript('!!document.querySelector("[data-sp-geo-lng]")', true);
})->group('browser');

it('saves a point somebody typed, with no device involved', function () {
    // The office case, and the survey case: coordinates that were never
    // measured by the thing being used to enter them.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = geoRuntimeApp();

    $page = visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    // Watch for the write to finish. Reading the database straight after the
    // click races the request — the other write tests in this suite all wait,
    // and this one was quietly failing for that reason alone.
    $page->script(<<<'JS'
        window.__done = false;
        const send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function () {
            this.addEventListener('loadend', () => (window.__done = true));
            return send.apply(this, arguments);
        };
        true
    JS);

    $page->script(<<<'JS'
        (() => {
            const set = (sel, value) => {
                const el = document.querySelector(sel);
                el.value = value;
                el.dispatchEvent(new Event('input', { bubbles: true }));
            };
            set('input[type=text]', 'Planta norte');
            set('[data-sp-geo-lat]', '19.4326');
            set('[data-sp-geo-lng]', '-99.1332');
            return true;
        })()
    JS);

    $page->click('Guardar');
    $page->assertNoJavaScriptErrors();

    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                if (window.__done === true) return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    $record = Record::where('app_id', $app->id)->first();

    expect($record)->not->toBeNull()
        ->and($record->data['donde']['lat'])->toBe(19.4326)
        ->and($record->data['donde']['lng'])->toBe(-99.1332);
})->group('browser');

it('says so and stays usable when the device refuses to locate', function () {
    // The rail. A refused permission must leave a field somebody can still
    // fill, not a button that does nothing and no explanation.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = geoRuntimeApp();

    $page = visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    // Refuse it, the way a browser does when somebody clicks "block".
    $page->script(<<<'JS'
        navigator.geolocation.getCurrentPosition = (ok, fail) =>
            fail({ code: 1, message: 'denied' });
        true
    JS);

    $page->script('document.querySelector("[data-sp-geo-locate]").click()');

    $page->assertNoJavaScriptErrors()
        ->assertSee('No se pudo obtener tu ubicación')
        // And the way in is still there.
        ->assertScript('!!document.querySelector("[data-sp-geo-lat]")', true);
})->group('browser');

it('fills both boxes from the device when it does locate', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = geoRuntimeApp();

    $page = visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        navigator.geolocation.getCurrentPosition = (ok) =>
            ok({ coords: { latitude: 19.432608, longitude: -99.133209, accuracy: 12 } });
        true
    JS);

    $page->script('document.querySelector("[data-sp-geo-locate]").click()');

    $page->assertNoJavaScriptErrors()
        // Six decimals: about a tenth of a metre, finer than any phone knows.
        ->assertScript('document.querySelector("[data-sp-geo-lat]").value', '19.432608')
        ->assertScript('document.querySelector("[data-sp-geo-lng]").value', '-99.133209');
})->group('browser');

it('opens a map to pick a point, and lets somebody back out', function () {
    // Headless Chrome has no GPU, so the tiles may never paint — what is
    // proved here is the sheet, its guard and the way out. The point of the
    // guard: an empty pin must not be acceptable, or somebody confirms a
    // location nobody chose.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = geoRuntimeApp();

    $page = visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script('document.querySelector("[data-sp-geo-pick]").click()');

    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (document.querySelector('[data-sp-geo-picker]')) return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    $page->assertSee('Toca el mapa y arrastra el pin')
        // Nothing chosen yet, so nothing to accept.
        ->assertScript(
            'document.querySelector("[data-sp-geo-picker-accept]").disabled',
            true,
        );

    $page->script('document.querySelector("[data-sp-geo-picker-close]").click()');

    $page->assertNoJavaScriptErrors()
        ->assertScript('!!document.querySelector("[data-sp-geo-picker]")', false)
        // And the coordinate boxes are still the way in.
        ->assertScript('!!document.querySelector("[data-sp-geo-lat]")', true);
})->group('browser');

it('does not make a form without a map pay for one', function () {
    // MapLibre and its style are about a megabyte. A form that never opens the
    // picker must not download them, which is why the component is lazy — and
    // a lazy import is exactly the kind of thing that quietly stops being lazy.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = geoRuntimeApp();

    visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript(<<<'JS'
            performance
                .getEntriesByType('resource')
                .filter((r) => /maplibre|GeoPicker/i.test(r.name)).length
        JS, 0);
})->group('browser');
