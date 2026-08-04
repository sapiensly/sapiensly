<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Print\HeadlessPdf;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * A printable copy of what is on the screen.
 *
 * An app could show somebody a work order and had no way to hand it to them:
 * the person who needs the paper is the customer, who does not have the app.
 *
 * What is worth testing here is NOT that Chromium works — that is Browsershot's
 * problem and it is absent on plenty of machines. It is the door: the render
 * route runs with no session, so if its signature were not the whole
 * authorization it would be a way to read any tenant's records.
 */
function pdfApp(User $owner): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'pdf_'.Str::lower(Str::random(6)),
        'name' => 'Órdenes',
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Órdenes',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_ordenes0001',
            'name' => 'Órdenes',
            'slug' => 'ordenes',
            'fields' => [
                ['id' => 'fld_folio00001', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_ordenes0001',
            'slug' => 'ordenes',
            'path' => '/ordenes',
            'name' => 'Órdenes',
            'blocks' => [[
                'id' => 'blk_tabla00001',
                'type' => 'table',
                'data_source' => ['object_id' => 'obj_ordenes0001'],
                'columns' => [['id' => 'col_folio00001', 'field_id' => 'fld_folio00001']],
            ], [
                // The affordance itself: a button that hands over the paper.
                'id' => 'blk_pdf00000001',
                'type' => 'button',
                'label' => 'Descargar PDF',
                'on_click' => [[
                    'type' => 'download_pdf',
                    'page_slug' => 'ordenes',
                    'params' => ['id' => '{{params.id}}'],
                ]],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ], $owner);

    return $app->fresh();
}

it('accepts download_pdf as an authored action', function () {
    // If the schema rejected it, a model would write the button, be told it
    // succeeded, and the app would fail to save.
    $owner = User::factory()->create();
    $app = pdfApp($owner);

    expect($app->current_version_id)->not->toBeNull();
});

it('renders the page through the runtime itself, so paper matches the screen', function () {
    // Not a print-only template built beside the real one: that is a second
    // thing to keep true, and the day they disagree somebody sends a customer
    // a PDF of something that is not on their screen.
    $owner = User::factory()->create();
    $app = pdfApp($owner);

    Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_ordenes0001',
        'organization_id' => $app->organization_id,
        'data' => ['folio' => 'OS-001'],
    ]);

    $url = URL::temporarySignedRoute('apps.runtime.print', now()->addMinutes(5), [
        'app_slug' => $app->slug,
        'page_slug' => 'ordenes',
        'uid' => $owner->id,
        'org' => $owner->organization_id,
    ]);

    // The rows themselves are NOT in this response: blockData is a deferred
    // Inertia prop that arrives on a second request. That is exactly why the
    // renderer waits on __spPrintReady, and why that flag now waits for the
    // data — see the browser test, which can watch it over time.
    $this->get($url)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('runtime/Page')
            // The chrome is off: paper has nowhere to navigate to.
            ->where('printing', true)
            ->where('app.slug', $app->slug),
        );
});

it('is worthless without the signature', function () {
    // The route has no session — the signature IS the authorization. Reachable
    // unsigned, it would be a way to read any tenant's records.
    $owner = User::factory()->create();
    $app = pdfApp($owner);

    $this->get("/apps/{$app->slug}/print/ordenes?uid={$owner->id}&org={$owner->organization_id}")
        ->assertStatus(403);
});

it('will not print for a user the signature does not match', function () {
    $owner = User::factory()->create();
    $app = pdfApp($owner);
    $stranger = User::factory()->create();

    // Signed, but naming somebody else: the org has to belong to the uid.
    $url = URL::temporarySignedRoute('apps.runtime.print', now()->addMinutes(5), [
        'app_slug' => $app->slug,
        'page_slug' => 'ordenes',
        'uid' => $stranger->id,
        'org' => $owner->organization_id,
    ]);

    $this->get($url)->assertNotFound();
});

it('is closed to somebody who cannot see the app', function () {
    $owner = User::factory()->create();
    $app = pdfApp($owner);

    $this->actingAs(User::factory()->create())
        ->get("/r/{$app->slug}/ordenes/pdf")
        ->assertNotFound();
});

it('says so plainly when the server cannot render', function () {
    // Rendering needs a browser on the server and plenty of machines have
    // none. A truncated download would send somebody looking in entirely the
    // wrong place.
    $this->mock(HeadlessPdf::class)
        ->shouldReceive('render')
        ->andReturn(null);

    $owner = User::factory()->create();
    $app = pdfApp($owner);

    $this->actingAs($owner)
        ->get("/r/{$app->slug}/ordenes/pdf")
        ->assertStatus(503);
});
