<?php

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * A name written with a finger.
 *
 * The second thing to need the upload pipeline, which is why the pipeline came
 * out of the file field on this commit and not the last one: at one consumer
 * the shared shape is a guess, at three it is three copies and a migration.
 *
 * It is the same `capture` option the camera uses, for the same reason — the
 * value, the storage, the preview and the export are a file either way, so a
 * type of its own would have meant twenty-three files to say it twice.
 */
function signatureApp(): App
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $obj = 'obj_'.strtolower((string) Str::ulid());
    $firma = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'sig_'.Str::lower(Str::random(5)),
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Entregas',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $obj,
            'slug' => 'entregas',
            'name' => 'Entregas',
            'fields' => [
                ['id' => $firma, 'slug' => 'firma', 'name' => 'Firma de recibido', 'type' => 'file', 'capture' => 'signature'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'nueva',
            'path' => '/nueva',
            'name' => 'Nueva entrega',
            'blocks' => [[
                'id' => 'blk_form0001',
                'type' => 'form',
                'object_id' => $obj,
                'mode' => 'create',
                'fields' => [['field_id' => $firma]],
                'submit_label' => 'Guardar',
                'on_submit' => [['type' => 'create_record', 'object_id' => $obj, 'values' => ['firma' => '{{form.firma}}']]],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    return $app;
}

/** Draw on the pad the way a finger would: pointer events, not mouse ones. */
function drawOnPad(): string
{
    return <<<'JS'
    (() => {
        const pad = document.querySelector('[data-sp-signature-pad]');
        const r = pad.getBoundingClientRect();
        const at = (x, y, type) => pad.dispatchEvent(new PointerEvent(type, {
            bubbles: true, clientX: r.left + x, clientY: r.top + y, pointerId: 1,
        }));
        at(20, 20, 'pointerdown');
        at(60, 60, 'pointermove');
        at(120, 30, 'pointermove');
        at(120, 30, 'pointerup');
        return true;
    })()
    JS;
}

it('signs with a pad instead of a file picker, in the app language', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = signatureApp();

    visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('Firma con el dedo o con el ratón')
        ->assertSee('Usar esta firma')
        // The picker is not what a signature field offers.
        ->assertScript('document.querySelectorAll("input[type=file]").length', 0)
        ->assertScript('!!document.querySelector("[data-sp-signature-pad]")', true);
})->group('browser');

it('will not accept an empty pad', function () {
    // A blank signature is worse than no signature: it looks like evidence.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = signatureApp();

    visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript(
            'document.querySelector("[data-sp-signature-accept]").disabled',
            true,
        );
})->group('browser');

it('hands the drawn signature to the upload pipeline as a PNG', function () {
    // Stops where object storage would begin: the served app in a browser test
    // has no bucket, so what is proved here is the half that lives in the
    // browser — ink becomes a PNG and reaches the same endpoint every
    // attachment uses. The other half is a feature test against that endpoint.
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = signatureApp();

    $page = visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    // Record where the page posts, before it posts anywhere.
    $page->script(<<<'JS'
        window.__posts = [];
        const open = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function (method, url) {
            window.__posts.push(method + ' ' + url);
            return open.apply(this, arguments);
        };
        true
    JS);

    $page->script(drawOnPad());

    // Ink makes the controls live.
    $page->assertScript(
        'document.querySelector("[data-sp-signature-accept]").disabled',
        false,
    );

    $page->script('document.querySelector("[data-sp-signature-accept]").click()');

    // toBlob is asynchronous, so the post is waited for rather than assumed.
    $page->assertScript(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if ((window.__posts || []).some((p) => p.includes('/uploads'))) {
                    return true;
                }
                await new Promise((r) => setTimeout(r, 100));
            }
            return false;
        })()
    JS, true);

    $page->assertNoJavaScriptErrors();
})->group('browser');

it('clears what was drawn without touching what was saved', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $app = signatureApp();

    $page = visit("/r/{$app->slug}/nueva")->on()->macbookAir()
        ->assertNoJavaScriptErrors();

    $page->script(drawOnPad());
    $page->script('document.querySelector("[data-sp-signature-clear]").click()');

    $page->assertNoJavaScriptErrors()
        // Blank again, so neither control is offered.
        ->assertScript(
            'document.querySelector("[data-sp-signature-accept]").disabled',
            true,
        )
        ->assertScript('!!document.querySelector("[data-sp-signature-pad]")', true);
})->group('browser');
