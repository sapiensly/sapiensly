<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\AuditAppTool;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppAuditService;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;

/**
 * Build a valid two-object app (Productos → Categorias, with a single_select on
 * Productos) via the real assembler, so the manifest half always validates and
 * the data half has a real relation + option field to check.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->appModel = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'audit_demo',
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($this->appModel), [
        'objects' => [
            ['name' => 'Categorias', 'slug' => 'categorias', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
            ]],
            ['name' => 'Productos', 'slug' => 'productos', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
                ['name' => 'Estado', 'slug' => 'estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'activo', 'label' => 'Activo'],
                    ['value' => 'inactivo', 'label' => 'Inactivo'],
                ]],
            ]],
        ],
        'links' => [['from' => 'productos', 'to' => 'categorias', 'name' => null]],
    ]);
    $manifests->createVersion($this->appModel, $manifest, $this->user, 'seed');
    // createVersion updates a separately-locked row; refresh so current_version_id
    // is set on our instance, exactly as the tool sees it via a fresh resolveApp.
    $this->appModel->refresh();

    $this->manifest = $manifest;
    $this->categoriasId = auditObjectId($manifest, 'categorias');
    $this->productosId = auditObjectId($manifest, 'productos');
    $this->relationSlug = manyToOneSlug($manifest, 'productos');
});

function auditObjectId(array $manifest, string $slug): string
{
    return collect($manifest['objects'])->firstWhere('slug', $slug)['id'];
}

/** The slug of the many_to_one relation field on an object (the FK the record stores). */
function manyToOneSlug(array $manifest, string $objectSlug): string
{
    $object = collect($manifest['objects'])->firstWhere('slug', $objectSlug);

    return collect($object['fields'])
        ->first(fn (array $f) => ($f['type'] ?? null) === 'relation' && ($f['cardinality'] ?? null) === 'many_to_one')['slug'];
}

function seedRecord(string $appId, string $objectId, array $data): Record
{
    return Record::factory()->create([
        'app_id' => $appId,
        'object_definition_id' => $objectId,
        'data' => $data,
    ]);
}

it('reports ok for an app whose records honour the manifest', function () {
    $cat = seedRecord($this->appModel->id, $this->categoriasId, ['nombre' => 'Electrónica']);
    seedRecord($this->appModel->id, $this->productosId, [$this->relationSlug => $cat->id, 'estado' => 'activo']);

    $report = app(AppAuditService::class)->audit($this->appModel);

    expect($report['summary']['ok'])->toBeTrue();
    expect($report['summary']['data_issues'])->toBe(0);
    expect($report['manifest']['valid'])->toBeTrue();
});

it('flags a dangling relation FK', function () {
    seedRecord($this->appModel->id, $this->productosId, [$this->relationSlug => 'rec_does_not_exist', 'estado' => 'activo']);

    $report = app(AppAuditService::class)->audit($this->appModel);

    expect($report['summary']['data_issues'])->toBe(1);
    $productos = collect($report['data']['objects'])->firstWhere('object', 'productos');
    expect($productos['dangling_relations']['count'])->toBe(1);
    expect($productos['dangling_relations']['samples'][0]['bad_value'])->toBe('rec_does_not_exist');
    expect($productos['dangling_relations']['samples'][0]['target_object'])->toBe('categorias');
});

it('flags a select value that is not in the field options', function () {
    $cat = seedRecord($this->appModel->id, $this->categoriasId, ['nombre' => 'Electrónica']);
    seedRecord($this->appModel->id, $this->productosId, [$this->relationSlug => $cat->id, 'estado' => 'descatalogado']);

    $report = app(AppAuditService::class)->audit($this->appModel);

    expect($report['summary']['data_issues'])->toBe(1);
    $productos = collect($report['data']['objects'])->firstWhere('object', 'productos');
    expect($productos['invalid_select_values']['count'])->toBe(1);
    expect($productos['invalid_select_values']['samples'][0]['bad_value'])->toBe('descatalogado');
});

it('flags records whose object was removed from the manifest', function () {
    seedRecord($this->appModel->id, 'obj_ghost_deleted', ['whatever' => 1]);
    seedRecord($this->appModel->id, 'obj_ghost_deleted', ['whatever' => 2]);

    $report = app(AppAuditService::class)->audit($this->appModel);

    expect($report['summary']['data_issues'])->toBe(2);
    expect($report['data']['orphaned_records']['total'])->toBe(2);
    expect($report['data']['orphaned_records']['by_object'][0]['object_definition_id'])->toBe('obj_ghost_deleted');
});

it('audit_app returns a report over MCP and is gated on apps:build', function () {
    seedRecord($this->appModel->id, $this->productosId, [$this->relationSlug => 'rec_missing', 'estado' => 'activo']);

    SapiensServer::actingAs($this->user)
        ->tool(AuditAppTool::class, ['app_slug' => 'audit_demo'])
        ->assertOk()
        ->assertSee('data_issues')
        ->assertSee('dangling_relations');
});

it('audit_app errors on an app the caller cannot see', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AuditAppTool::class, ['app_slug' => 'nope_not_here'])
        ->assertHasErrors();
});
