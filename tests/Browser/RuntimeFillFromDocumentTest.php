<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * A form that fills itself from a photograph, and still waits to be checked.
 *
 * The browser half is where the promise is either kept or broken. The server
 * returns values and writes nothing; what has to be true HERE is that the
 * values land in the inputs, that nothing is saved until somebody presses the
 * button, and that whatever they already typed survives — a model is not
 * entitled to overwrite a person's own work.
 */
function fillDocApp(bool $enabled = true): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $obj = 'obj_'.strtolower((string) Str::ulid());
    $folio = 'fld_'.strtolower((string) Str::ulid());
    $total = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'fil_'.Str::lower(Str::random(5)),
    ]);

    $form = [
        'id' => 'blk_form0001',
        'type' => 'form',
        'object_id' => $obj,
        'mode' => 'create',
        'fields' => [['field_id' => $folio], ['field_id' => $total]],
        'submit_label' => 'Guardar',
        'on_submit' => [[
            'type' => 'create_record',
            'object_id' => $obj,
            'values' => ['folio' => '{{form.folio}}', 'total' => '{{form.total}}'],
        ]],
    ];

    if ($enabled) {
        $form['fill_from_document'] = true;
    }

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Facturas',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $obj,
            'slug' => 'facturas',
            'name' => 'Facturas',
            'fields' => [
                ['id' => $folio, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
                ['id' => $total, 'slug' => 'total', 'name' => 'Total', 'type' => 'number'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'nueva',
            'path' => '/nueva',
            'name' => 'Nueva factura',
            'blocks' => [$form],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    return $app;
}

/**
 * Answer the extract endpoint without a model or a bucket. What is under test
 * is what the FORM does with an extraction, not whether a vision model reads
 * receipts — that part has no assertion anybody can write.
 */
function stubExtraction(string $json): string
{
    return <<<JS
    (() => {
        const open = XMLHttpRequest.prototype.open;
        const send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) {
            this.__url = String(url);
            return open.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            if (this.__url.includes('/uploads')) {
                Object.defineProperty(this, 'status', { value: 201 });
                Object.defineProperty(this, 'responseText', {
                    value: JSON.stringify({ file_id: 'fil_x', original_name: 'f.png', mime: 'image/png', size_bytes: 1, url: '/x' }),
                });
                Object.defineProperty(this, 'response', { value: this.responseText });
                Object.defineProperty(this, 'readyState', { value: 4 });
                setTimeout(() => this.dispatchEvent(new Event('load')), 5);
                setTimeout(() => this.dispatchEvent(new Event('loadend')), 6);
                return;
            }
            if (this.__url.includes('/extract')) {
                Object.defineProperty(this, 'status', { value: 200 });
                Object.defineProperty(this, 'responseText', { value: {$json} });
                Object.defineProperty(this, 'response', { value: this.responseText });
                Object.defineProperty(this, 'readyState', { value: 4 });
                setTimeout(() => this.dispatchEvent(new Event('load')), 5);
                setTimeout(() => this.dispatchEvent(new Event('loadend')), 6);
                return;
            }
            return send.apply(this, arguments);
        };
        return true;
    })()
    JS;
}

/** Put a file on the hidden input the way a camera or a picker would. */
function chooseDocument(): string
{
    return <<<'JS'
    (() => {
        const input = document.querySelector('[data-sp-fill-doc]')
            .closest('div')
            .querySelector('input[type=file]');
        const data = new DataTransfer();
        data.items.add(new File(['x'], 'factura.png', { type: 'image/png' }));
        input.files = data.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    })()
    JS;
}

it('offers the button only where the manifest asked for it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    visit('/r/'.fillDocApp(enabled: false)->slug.'/nueva')->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript('document.querySelectorAll("[data-sp-fill-doc]").length', 0);
})->group('browser');

it('fills the form and saves nothing until somebody says so', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = fillDocApp();

    $page = visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Rellenar desde una foto o PDF')
        // The standing reminder: it fills, the person checks.
        ->assertSee('Revisa cada dato antes de guardar');

    $page->script(stubExtraction('JSON.stringify({ values: { folio: "A-1", total: 1250 }, error: null })'));
    $page->script(chooseDocument());

    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                const el = document.querySelector('input[type=text]');
                if (el && el.value === 'A-1') return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    $page->assertNoJavaScriptErrors();

    // The whole promise: an extraction is a suggestion, not a record.
    expect(Record::where('app_id', $app->id)->count())->toBe(0);
})->group('browser');

it('never overwrites what a person already typed', function () {
    // A model is not entitled to a value somebody put there on purpose.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = fillDocApp();

    $page = visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        (() => {
            const el = document.querySelector('input[type=text]');
            el.value = 'MÍO-99';
            el.dispatchEvent(new Event('input', { bubbles: true }));
            return true;
        })()
    JS);

    $page->script(stubExtraction('JSON.stringify({ values: { folio: "A-1", total: 1250 }, error: null })'));
    $page->script(chooseDocument());

    // The number arrives; the folio stays theirs.
    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                const num = document.querySelector('input[type=number]');
                if (num && num.value === '1250') return true;
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    $page->assertScript('document.querySelector("input[type=text]").value', 'MÍO-99');
})->group('browser');

it('says so and leaves a usable form when nothing could be read', function () {
    // A photograph of a bad photocopy. The rail: it ends somewhere a person
    // can still type.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = fillDocApp();

    $page = visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script(stubExtraction('JSON.stringify({ values: {}, error: null })'));
    $page->script(chooseDocument());

    $page->assertSee('No se pudo leer nada')
        ->assertNoJavaScriptErrors()
        ->assertScript('document.querySelectorAll("input[type=text]").length', 1);
})->group('browser');
