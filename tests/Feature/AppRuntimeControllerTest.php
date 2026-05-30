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
            ->has('blockData.'.$this->statId.'.value')
            ->where('blockData.'.$this->statId.'.value', 3500)
            ->has('blockData.'.$this->tableId.'.rows', 2)
        );
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

    $this->actingAs($this->user)
        ->get('/r/rcrm')
        ->assertInertia(fn ($page) => $page
            ->where('blockData.'.$this->tableId.'.rows.0.data.nombre', 'Aaron')
            ->where('blockData.'.$this->tableId.'.rows.1.data.nombre', 'Ana')
            ->where('blockData.'.$this->tableId.'.rows.2.data.nombre', 'Beto')
        );
});
