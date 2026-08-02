<?php

use App\Models\App;
use App\Models\AppVersion;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The two documents, reachable from the builder.
 *
 * The whole point of the feature is that they are ALWAYS there — so what these
 * pin is the reaching: the route exists, it is gated like the app itself, and
 * both documents arrive in one payload so the tab switch is not a round trip.
 */
function docsApp(User $owner): App
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'rentas-'.Str::lower(Str::random(6)),
        'name' => 'Rentas',
    ]);

    $version = AppVersion::create([
        'id' => 'ver_'.strtolower((string) Str::ulid()),
        'app_id' => $app->id,
        'version_number' => 1,
        'created_by' => $owner->id,
        'manifest' => [
            'schema_version' => '1.0.0',
            'id' => $app->id,
            'slug' => $app->slug,
            'name' => 'Rentas',
            'description' => 'Lleva contratos de renta y sus pagos.',
            'version' => 1,
            'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
            'objects' => [[
                'id' => 'obj_leases',
                'name' => 'Contratos',
                'slug' => 'leases',
                'fields' => [['id' => 'fld_folio', 'name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'required' => true]],
            ]],
            'pages' => [[
                'id' => 'pag_leases',
                'name' => 'Contratos',
                'slug' => 'leases',
                'path' => '/leases',
                'blocks' => [['id' => 'blk_table', 'type' => 'table', 'object_id' => 'obj_leases', 'columns' => [['id' => 'col_folio', 'field_id' => 'fld_folio']]]],
            ]],
            'permissions' => ['roles' => [['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin']]],
        ],
    ]);

    $app->update(['current_version_id' => $version->id]);

    return $app->fresh();
}

it('serves both documents in one payload so switching tabs is not a request', function () {
    $owner = User::factory()->create();
    $app = docsApp($owner);

    $this->actingAs($owner)
        ->get(route('apps.docs', ['app' => $app->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('apps/Docs')
            ->where('kind', 'manual')
            ->where('documents.manual.title', 'Manual de uso')
            ->where('documents.technical.title', 'Ficha técnica')
            ->where('app.name', 'Rentas')
        );
});

it('opens on the document that was asked for', function () {
    $owner = User::factory()->create();
    $app = docsApp($owner);

    $this->actingAs($owner)
        ->get(route('apps.docs', ['app' => $app->id]).'?kind=technical')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('kind', 'technical'));
});

it('downloads either document as markdown', function () {
    $owner = User::factory()->create();
    $app = docsApp($owner);

    $response = $this->actingAs($owner)
        ->get(route('apps.docs.download', ['app' => $app->id, 'kind' => 'manual']));

    $response->assertOk()
        ->assertHeader('content-type', 'text/markdown; charset=UTF-8')
        ->assertDownload($app->slug.'-manual.md');

    expect($response->streamedContent())->toContain('# Manual de uso');
});

it('refuses a document kind it does not write', function () {
    $owner = User::factory()->create();
    $app = docsApp($owner);

    // The route constraint catches it before the controller does; pinned
    // because the kind reaches a file name.
    $this->actingAs($owner)
        ->get('/apps/'.$app->id.'/docs/everything.md')
        ->assertNotFound();
});

it('is gated on seeing the app, not on owning it', function () {
    $owner = User::factory()->create();
    $app = docsApp($owner);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('apps.docs', ['app' => $app->id]))
        ->assertForbidden();
});
