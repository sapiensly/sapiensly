<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

function rid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function buildManifest(string $appId, string $slug, array $overrides = []): array
{
    $objId = rid('obj');
    $nombre = rid('fld');
    $monto = rid('fld');
    $pageId = rid('pag');
    $tableId = rid('blk');
    $statId = rid('blk');

    return array_replace_recursive([
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => $slug,
        'name' => 'Runtime Test',
        'version' => 1,
        'objects' => [[
            'id' => $objId,
            'slug' => 'clientes',
            'name' => 'Cliente',
            'fields' => [
                ['id' => $nombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
                ['id' => $monto, 'slug' => 'monto', 'name' => 'Monto', 'type' => 'currency', 'currency_code' => 'MXN'],
            ],
        ]],
        'pages' => [[
            'id' => $pageId,
            'slug' => 'clientes',
            'name' => 'Clientes',
            'path' => '/clientes',
            'blocks' => [
                [
                    'id' => $statId,
                    'type' => 'stat',
                    'label' => 'Total',
                    'query' => ['object_id' => $objId],
                    'aggregation' => 'sum',
                    'field_id' => $monto,
                    'format' => 'currency',
                ],
                [
                    'id' => $tableId,
                    'type' => 'table',
                    'data_source' => ['object_id' => $objId, 'sort' => [['field_id' => $nombre, 'direction' => 'asc']]],
                    'columns' => [
                        ['id' => rid('col'), 'field_id' => $nombre],
                        ['id' => rid('col'), 'field_id' => $monto],
                    ],
                ],
            ],
        ]],
        'permissions' => [
            'roles' => [['id' => rid('rol'), 'slug' => 'admin', 'name' => 'Admin']],
        ],
        // bag the ids back to the caller via a non-validated key — stripped before validate()
    ], $overrides);
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);

    $this->testApp = App::create([
        'user_id' => $this->user->id,
        'slug' => 'rcrm',
        'name' => 'Runtime CRM',
        'visibility' => 'private',
    ]);

    $manifest = buildManifest($this->testApp->id, 'rcrm');
    $this->objectId = $manifest['objects'][0]['id'];
    $this->statId = $manifest['pages'][0]['blocks'][0]['id'];
    $this->tableId = $manifest['pages'][0]['blocks'][1]['id'];

    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $this->objectId,
        'data' => ['nombre' => 'Ana', 'monto' => 1000],
    ]);
    Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $this->objectId,
        'data' => ['nombre' => 'Beto', 'monto' => 2500],
    ]);
});

it('redirects guests to login', function () {
    $this->get('/r/rcrm')->assertRedirect('/login');
});

it('renders the first page when no page_slug is given', function () {
    $this->actingAs($this->user)
        ->get('/r/rcrm')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('runtime/Page')
            ->where('app.slug', 'rcrm')
            ->where('page.slug', 'clientes')
            // blockData is DEFERRED — the shell ships without it.
            ->missing('blockData')
        );

    deferredBlockData($this->actingAs($this->user), '/r/rcrm')
        ->assertOk()
        ->assertJsonPath('props.blockData.'.$this->statId.'.value', 3500)
        ->assertJsonCount(2, 'props.blockData.'.$this->tableId.'.rows');
});

it('renders the explicit page by slug', function () {
    $this->actingAs($this->user)
        ->get('/r/rcrm/clientes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('page.slug', 'clientes'));
});

it('returns 404 when the App slug does not exist', function () {
    $this->actingAs($this->user)
        ->get('/r/nonexistent')
        ->assertNotFound();
});

it('returns 404 when the requested page_slug is not in the manifest', function () {
    $this->actingAs($this->user)
        ->get('/r/rcrm/ghost')
        ->assertNotFound();
});

it('returns 404 when the user has no visibility on the App', function () {
    $other = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($other)
        ->get('/r/rcrm')
        ->assertNotFound();
});

it('sorts table rows according to the data_source sort directive', function () {
    Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $this->objectId,
        'data' => ['nombre' => 'Aaron', 'monto' => 500],
    ]);

    deferredBlockData($this->actingAs($this->user), '/r/rcrm')
        ->assertJsonPath('props.blockData.'.$this->tableId.'.rows.0.data.nombre', 'Aaron')
        ->assertJsonPath('props.blockData.'.$this->tableId.'.rows.1.data.nombre', 'Ana')
        ->assertJsonPath('props.blockData.'.$this->tableId.'.rows.2.data.nombre', 'Beto');
});

it('compiles author custom_css scoped to the app surface', function () {
    $app = App::create([
        'user_id' => $this->user->id,
        'slug' => 'styled',
        'name' => 'Styled',
        'visibility' => 'private',
    ]);
    $manifest = buildManifest($app->id, 'styled', [
        'settings' => ['custom_css' => '[data-block-type="stat"] { border-radius: 12px; }'],
    ]);
    app(AppManifestService::class)->createVersion($app, $manifest, $this->user);

    $this->actingAs($this->user)
        ->get('/r/styled')
        ->assertInertia(fn ($page) => $page
            ->where('customCss', fn ($css) => str_starts_with($css, '.sp-app-surface {')
                && str_contains($css, '[data-block-type="stat"]'))
        );
});

it('honors a visibility expression against page params', function () {
    $app = App::create([
        'user_id' => $this->user->id,
        'slug' => 'visapp',
        'name' => 'Vis',
        'visibility' => 'private',
    ]);
    $objId = rid('obj');
    $fld = rid('fld');
    $whenFlag = rid('blk');
    $whenNoFlag = rid('blk');
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'visapp',
        'name' => 'Vis',
        'version' => 1,
        'objects' => [['id' => $objId, 'slug' => 'items', 'name' => 'Items', 'fields' => [
            ['id' => $fld, 'slug' => 'monto', 'name' => 'Monto', 'type' => 'currency', 'currency_code' => 'MXN'],
        ]]],
        'pages' => [['id' => rid('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/', 'blocks' => [
            ['id' => $whenFlag, 'type' => 'stat', 'label' => 'With flag', 'query' => ['object_id' => $objId], 'aggregation' => 'count', 'visibility' => ['expression' => '{{params.flag}}']],
            ['id' => $whenNoFlag, 'type' => 'stat', 'label' => 'No flag', 'query' => ['object_id' => $objId], 'aggregation' => 'count', 'visibility' => ['expression' => '{{not params.flag}}']],
        ]]],
        'permissions' => ['roles' => [['id' => rid('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
    app(AppManifestService::class)->createVersion($app, $manifest, $this->user);

    // No flag → only the "no flag" block survives.
    deferredBlockData($this->actingAs($this->user), '/r/visapp/home')->assertOk()
        ->assertJsonMissingPath('props.blockData.'.$whenFlag)
        ->assertJsonPath('props.blockData.'.$whenNoFlag.'.value', fn ($v) => $v !== null);

    // Flag set → only the "with flag" block survives.
    deferredBlockData($this->actingAs($this->user), '/r/visapp/home?flag=1')->assertOk()
        ->assertJsonPath('props.blockData.'.$whenFlag.'.value', fn ($v) => $v !== null)
        ->assertJsonMissingPath('props.blockData.'.$whenNoFlag);
});

it('keeps record-scoped detail pages out of the navigation and lands on the first list page', function () {
    $app = App::create([
        'user_id' => $this->user->id,
        'slug' => 'rnav',
        'name' => 'Nav Rule',
        'visibility' => 'private',
    ]);

    $objId = rid('obj');
    $nombre = rid('fld');
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'rnav',
        'name' => 'Nav Rule',
        'version' => 1,
        'objects' => [[
            'id' => $objId,
            'slug' => 'clientes',
            'name' => 'Cliente',
            'fields' => [['id' => $nombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string']],
        ]],
        'pages' => [
            ['id' => rid('pag'), 'slug' => 'clientes', 'name' => 'Clientes', 'path' => '/clientes', 'blocks' => [
                ['id' => rid('blk'), 'type' => 'table', 'data_source' => ['object_id' => $objId], 'columns' => [
                    ['id' => rid('col'), 'field_id' => $nombre],
                ]],
            ]],
            ['id' => rid('pag'), 'slug' => 'clientes_detail', 'name' => 'Cliente', 'path' => '/clientes_detail', 'blocks' => [
                ['id' => rid('blk'), 'type' => 'record_detail', 'object_id' => $objId, 'record_id_expression' => '{{params.id}}', 'fields' => [
                    ['field_id' => $nombre],
                ]],
            ]],
        ],
        'permissions' => ['roles' => [['id' => rid('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
    app(AppManifestService::class)->createVersion($app, $manifest, $this->user);

    // Landing skips the detail page; the nav flags mark list=true, detail=false.
    $this->actingAs($this->user)
        ->get('/r/rnav')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('page.slug', 'clientes')
            ->where('activeSlug', 'clientes')
            ->where('manifest.pages.0.slug', 'clientes')
            ->where('manifest.pages.0.nav', true)
            ->where('manifest.pages.1.slug', 'clientes_detail')
            ->where('manifest.pages.1.nav', false)
        );

    // The detail page stays directly reachable when drilled into — and reports
    // its parent list's slug so the menu keeps "Clientes" active.
    $this->actingAs($this->user)
        ->get('/r/rnav/clientes_detail')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('page.slug', 'clientes_detail')
            ->where('activeSlug', 'clientes')
        );
});
