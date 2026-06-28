<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Records\DemoDataGenerator;
use Inertia\Testing\AssertableInertia;

/**
 * L3 smoke: assemble a scaffolded app from a plain spec (deterministic, no LLM),
 * seed sample data, then render EVERY page and fail if any block's data resolved
 * to an error. This is the headless safety net that catches a generated page that
 * 500s or a block whose data_source/aggregation throws — the "broken/unusable UI"
 * the manual eyeballing was finding. (A visual browser layer can sit on top once
 * Playwright is available.)
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

/**
 * Build + version a scaffolded app from a spec, seed demo data, and smoke every
 * page. Returns the assembled manifest.
 *
 * @param  array<string, mixed>  $spec
 * @return array<string, mixed>
 */
function smokeScaffold(string $slug, array $spec): array
{
    $user = test()->user;
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'slug' => $slug,
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($app), $spec);
    $manifests->createVersion($app, $manifest, $user, 'smoke');

    // Real sample data so tables/charts/rollups resolve over actual rows.
    app(DemoDataGenerator::class)->generate($app, $manifest, 3, null, $user);

    $errors = [];
    foreach ($manifest['pages'] as $page) {
        test()->actingAs($user)
            ->get("/r/{$slug}/{$page['slug']}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $rendered) use (&$errors, $page) {
                $blockData = $rendered->toArray()['props']['blockData'] ?? [];
                collectBlockErrors($blockData, $page['slug'], $errors);
            });
    }

    expect($errors)->toBe([]);

    return $manifest;
}

/**
 * Walk a resolved blockData tree and record every `error` marker
 * BlockDataResolver emitted (per block, metric_grid item, or funnel stage).
 *
 * @param  array<string, mixed>  $node
 * @param  list<string>  $errors
 */
function collectBlockErrors(mixed $node, string $path, array &$errors): void
{
    if (! is_array($node)) {
        return;
    }
    foreach ($node as $key => $value) {
        if ($key === 'error' && is_string($value)) {
            $errors[] = "{$path}: {$value}";

            continue;
        }
        collectBlockErrors($value, "{$path}.{$key}", $errors);
    }
}

it('smoke: a simple CRUD app renders every page without block errors', function () {
    smokeScaffold('smoke_crm', [
        'objects' => [[
            'name' => 'Clientes', 'slug' => 'clientes', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'activo', 'label' => 'Activo'], ['value' => 'baja', 'label' => 'Baja'],
                ]],
                ['name' => 'Valor', 'slug' => 'valor', 'type' => 'currency', 'options' => null],
            ],
        ]],
        'links' => [],
    ]);
});

it('smoke: a master-detail app renders every page without block errors', function () {
    smokeScaffold('smoke_md', [
        'objects' => [
            ['name' => 'Proyectos', 'slug' => 'proyectos', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
            ]],
            ['name' => 'Tareas', 'slug' => 'tareas', 'fields' => [
                ['name' => 'Titulo', 'slug' => 'titulo', 'type' => 'string', 'options' => null],
                ['name' => 'Horas', 'slug' => 'horas', 'type' => 'number', 'options' => null],
            ]],
        ],
        'links' => [['from' => 'tareas', 'to' => 'proyectos', 'name' => 'proyecto']],
    ]);
});

it('smoke: a POS app renders every page (incl. the generated POS screen) cleanly', function () {
    smokeScaffold('smoke_pos', [
        'objects' => [
            ['name' => 'Comandas', 'slug' => 'comandas', 'fields' => [
                ['name' => 'Folio', 'slug' => 'folio', 'type' => 'string', 'options' => null],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'abierta', 'label' => 'Abierta'], ['value' => 'pagada', 'label' => 'Pagada'],
                ]],
            ]],
            ['name' => 'Platillos', 'slug' => 'platillos', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
                ['name' => 'Precio', 'slug' => 'precio', 'type' => 'currency', 'options' => null],
            ]],
            ['name' => 'Renglones', 'slug' => 'renglones', 'fields' => [
                ['name' => 'Cantidad', 'slug' => 'cantidad', 'type' => 'number', 'options' => null],
            ]],
        ],
        'links' => [
            ['from' => 'renglones', 'to' => 'comandas', 'name' => 'comanda'],
            ['from' => 'renglones', 'to' => 'platillos', 'name' => 'platillo'],
        ],
    ]);
});
