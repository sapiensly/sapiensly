<?php

use App\Ai\Tools\Platform\PlatformToolsFactory;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Data\AnalyzeAppDataTool;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The analyst, reachable from outside the builder: one MCP tool over the same
 * AnalystCore, which is what puts it in the hands of every agent and chatbot
 * too (PlatformToolsFactory bridges the MCP catalog into internal agent runs).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    config(['cache.default' => 'array']);

    $this->user = User::factory()->create();
    $this->appModel = App::factory()->create(['user_id' => $this->user->id, 'slug' => 'soporte_mcp']);

    app(AppManifestService::class)->createVersion($this->appModel, [
        'schema_version' => '1.0.0',
        'id' => $this->appModel->id,
        'slug' => 'soporte_mcp',
        'name' => 'Soporte',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_mcp00000000',
            'slug' => 'tickets',
            'name' => 'Tickets',
            'fields' => [
                ['id' => 'fld_mreason000', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
                ['id' => 'fld_mtotal0000', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_mcp0000000', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ], $this->user);

    // A few reasons carry most of the volume → the concentration fact fires.
    foreach ([['Envíos', 412], ['Cobranza', 286], ['Garantías', 96], ['Precompra', 74], ['Créditos', 52], ['Devoluciones', 29], ['Otros', 15]] as [$reason, $total]) {
        Record::create([
            'app_id' => $this->appModel->id,
            'object_definition_id' => 'obj_mcp00000000',
            'data' => ['reason' => $reason, 'total_tickets' => $total],
        ]);
    }
});

it('analyze_app_data returns findings grounded in the real records', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AnalyzeAppDataTool::class, ['app_slug' => 'soporte_mcp'])
        ->assertOk()
        // It read the records themselves, and knew what business it was looking at.
        ->assertSee('"sources":1')
        ->assertSee('"total_rows":7')
        ->assertSee('"sector":"support"')
        ->assertSee('"kind":"pareto"')
        // The finding carries a manifest block ready for propose_change — the
        // agent never has to invent a chart spec from a field list.
        ->assertSee('"chart_type":"pareto"')
        ->assertSee('"group_by_field_id":"fld_mreason000"')
        ->assertSee('"y_field_id":"fld_mtotal0000"')
        ->assertSee('"object_id":"obj_mcp00000000"')
        // …and the key the caller feeds back as `exclude` next time.
        ->assertSee('"semantic_key":"breakdown|total tickets|reason"')
        // Ids belong to the manifest, not to the analysis.
        ->assertDontSee('"id":"blk_');
});

it('analyze_app_data never proposes what the caller already shows', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AnalyzeAppDataTool::class, [
            'app_slug' => 'soporte_mcp',
            'exclude' => ['breakdown|total tickets|reason'],
        ])
        ->assertOk()
        ->assertDontSee('"kind":"pareto"');
});

it('every agent and chatbot inherits the analyst', function () {
    // The bridge is opt-out: a read-only tool reaches internal agent runs unless
    // it is denied. This is what makes ONE registration light up every surface.
    expect(SapiensServer::TOOLS)->toContain(AnalyzeAppDataTool::class)
        ->and(PlatformToolsFactory::DENYLIST)->not->toContain('analyze_app_data')
        ->and(PlatformToolsFactory::CONFIRM_REQUIRED)->not->toContain('analyze_app_data');
});
