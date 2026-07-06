<?php

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\Integration;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Express\ExpressContext;
use App\Services\Express\Phases\VerifyRenderPhase;
use App\Services\Express\QualityAudit;
use App\Services\Manifest\AppManifestService;
use App\Services\Tools\McpClient;
use Illuminate\Support\Str;

/**
 * The render-and-verify quality gate: the page is resolved FOR REAL and
 * blocks whose rendered numbers are degenerate get repaired away (while
 * keeping the structural lints green) or honestly noted.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->conv = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'active',
    ]);
    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1', 'is_mcp' => true,
        'auth_type' => 'bearer', 'auth_config' => ['token' => 'T'], 'status' => 'active',
    ]);

    $mk = fn (string $x) => 'fld_'.strtolower((string) Str::ulid()).$x;
    $this->ids = ['d' => $mk('a'), 'c' => $mk('b'), 'n' => $mk('c')];
    $this->object = [
        'id' => 'obj_'.strtolower((string) Str::ulid()),
        'slug' => 'serie', 'name' => 'Serie',
        'fields' => [
            ['id' => $this->ids['d'], 'slug' => 'semana', 'name' => 'Semana', 'type' => 'date'],
            ['id' => $this->ids['c'], 'slug' => 'categoria', 'name' => 'Categoría', 'type' => 'string'],
            ['id' => $this->ids['n'], 'slug' => 'total', 'name' => 'Total', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => $this->integration->id, 'id_path' => 'id',
            'operations' => ['list' => ['mcp_tool' => 'serie-tool', 'collection_path' => 'series']],
            'field_map' => [
                ['field_id' => $this->ids['d'], 'external_path' => 'semana'],
                ['field_id' => $this->ids['c'], 'external_path' => 'categoria'],
                ['field_id' => $this->ids['n'], 'external_path' => 'total'],
            ],
        ],
    ];

    app(AppManifestService::class)->createVersion($this->testApp, [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'rv_'.strtolower(Str::random(6)),
        'name' => 'RV',
        'version' => 1,
        'objects' => [$this->object],
        'pages' => [],
        'settings' => ['default_locale' => 'es-MX'],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $this->user);
});

function rv_mock_rows($test, array $rows): void
{
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->andReturn(['series' => $rows]);
    app()->instance(McpClient::class, $mcp);
}

function rv_page($test, array $charts): array
{
    $blocks = [
        ['id' => 'blk_kpigrid'.strtolower(Str::random(4)), 'type' => 'metric_grid', 'columns' => 2, 'items' => [
            ['id' => 'itm_totalsum1', 'label' => 'Total', 'aggregation' => 'sum', 'field_id' => $test->ids['n'],
                'query' => ['object_id' => $test->object['id']]],
            ['id' => 'itm_totalavg1', 'label' => 'Promedio', 'aggregation' => 'avg', 'field_id' => $test->ids['n'],
                'query' => ['object_id' => $test->object['id']]],
        ]],
        ...$charts,
        ['id' => 'in_insight'.strtolower(Str::random(4)), 'type' => 'insight', 'variant' => 'conclusion',
            'title' => 'Volumen', 'body' => 'Texto.'],
    ];

    return [
        'id' => 'pag_'.strtolower((string) Str::ulid()),
        'name' => 'Panel RV', 'slug' => 'panel_rv', 'path' => '/panel_rv',
        'blocks' => $blocks,
    ];
}

it('drops a chart that rendered too few points and applies the repair as a version', function () {
    rv_mock_rows($this, [
        ['id' => 'W1', 'semana' => now()->utc()->subDays(3)->toDateString(), 'categoria' => 'Envíos', 'total' => 12],
        ['id' => 'W2', 'semana' => now()->utc()->subDays(10)->toDateString(), 'categoria' => 'Pagos', 'total' => 7],
        ['id' => 'W3', 'semana' => now()->utc()->subDays(17)->toDateString(), 'categoria' => 'Envíos', 'total' => 9],
    ]);

    $good = ['id' => 'blk_goodline1', 'type' => 'chart', 'label' => 'Evolución', 'chart_type' => 'line',
        'x_field_id' => $this->ids['d'], 'aggregation' => 'sum', 'y_field_id' => $this->ids['n'],
        'data_source' => ['object_id' => $this->object['id']]];
    $alsoGood = ['id' => 'blk_gooddonut', 'type' => 'chart', 'label' => 'Por categoría', 'chart_type' => 'donut',
        'aggregation' => 'sum', 'y_field_id' => $this->ids['n'], 'group_by_field_id' => $this->ids['c'],
        'data_source' => ['object_id' => $this->object['id']]];
    // A filter that matches nothing → 0 rows → nothing to draw.
    $degenerate = ['id' => 'blk_emptybar1', 'type' => 'chart', 'label' => 'Vacío', 'chart_type' => 'bar',
        'aggregation' => 'sum', 'y_field_id' => $this->ids['n'], 'group_by_field_id' => $this->ids['c'],
        'data_source' => ['object_id' => $this->object['id'],
            'filter' => ['op' => 'eq', 'field_id' => $this->ids['c'], 'value' => 'noexiste']]];

    $manifests = app(AppManifestService::class);
    $page = rv_page($this, [$good, $alsoGood, $degenerate]);
    $before = $manifests->applyPatch($this->testApp->fresh(), [['op' => 'add', 'path' => '/pages/-', 'value' => $page]], $this->user, 'page');

    $ctx = new ExpressContext($this->testApp->fresh(), $this->user, $this->conv, 'dashboard');
    $ctx->page = ['slug' => 'panel_rv', 'path' => '/panel_rv', 'name' => 'Panel RV', 'version' => $before->version_number, 'version_id' => $before->id];
    $run = PipelineRun::create(['app_id' => $this->testApp->id, 'conversation_id' => $this->conv->id, 'prompt' => 'p']);

    app(VerifyRenderPhase::class)->run($ctx, $run);

    $manifest = $manifests->getActiveManifest($this->testApp->fresh());
    $repaired = collect($manifest['pages'])->firstWhere('slug', 'panel_rv');
    $json = json_encode($repaired);

    expect($json)->not->toContain('blk_emptybar1')      // degenerate dropped
        ->and($json)->toContain('blk_goodline1')         // healthy charts kept
        ->and($json)->toContain('blk_gooddonut')
        ->and($ctx->page['version'])->toBeGreaterThan($before->version_number)
        ->and(implode(' ', $ctx->notes))->toContain('Vacío')
        ->and($run->fresh()->gates['verify_render']['issues'])->toBe(1)
        ->and($ctx->renderedSummary)->not->toBeEmpty();
});

it('keeps a weak chart when dropping it would break the dashboard lints, noting it honestly', function () {
    // ONE flat-series chart: dropping it would leave a chartless page.
    rv_mock_rows($this, [
        ['id' => 'W1', 'semana' => now()->utc()->subDays(3)->toDateString(), 'categoria' => 'Envíos', 'total' => 5],
        ['id' => 'W2', 'semana' => now()->utc()->subDays(10)->toDateString(), 'categoria' => 'Pagos', 'total' => 5],
        ['id' => 'W3', 'semana' => now()->utc()->subDays(17)->toDateString(), 'categoria' => 'Otros', 'total' => 5],
    ]);

    $flat = ['id' => 'blk_flatline1', 'type' => 'chart', 'label' => 'Plano', 'chart_type' => 'line',
        'x_field_id' => $this->ids['d'], 'aggregation' => 'sum', 'y_field_id' => $this->ids['n'],
        'data_source' => ['object_id' => $this->object['id']]];

    $manifests = app(AppManifestService::class);
    $page = rv_page($this, [$flat]);
    $before = $manifests->applyPatch($this->testApp->fresh(), [['op' => 'add', 'path' => '/pages/-', 'value' => $page]], $this->user, 'page');

    $ctx = new ExpressContext($this->testApp->fresh(), $this->user, $this->conv, 'dashboard');
    $ctx->page = ['slug' => 'panel_rv', 'path' => '/panel_rv', 'name' => 'Panel RV', 'version' => $before->version_number, 'version_id' => $before->id];
    $run = PipelineRun::create(['app_id' => $this->testApp->id, 'conversation_id' => $this->conv->id, 'prompt' => 'p']);

    app(VerifyRenderPhase::class)->run($ctx, $run);

    $manifest = $manifests->getActiveManifest($this->testApp->fresh());
    expect(json_encode(collect($manifest['pages'])->firstWhere('slug', 'panel_rv')))->toContain('blk_flatline1')
        ->and($ctx->page['version'])->toBe($before->version_number) // no repair version
        ->and(implode(' ', $ctx->notes))->toContain('idénticos');
});

it('QualityAudit reports null KPIs and load errors as non-repairable', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->andThrow(new RuntimeException('fuente caída'));
    app()->instance(McpClient::class, $mcp);

    $page = rv_page($this, [[
        'id' => 'blk_deadchart', 'type' => 'chart', 'label' => 'Muerto', 'chart_type' => 'bar',
        'aggregation' => 'sum', 'y_field_id' => $this->ids['n'], 'group_by_field_id' => $this->ids['c'],
        'data_source' => ['object_id' => $this->object['id']],
    ]]);
    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    $manifest['pages'][] = $page;

    $result = app(QualityAudit::class)->audit($this->testApp->fresh(), $page, $manifest, $this->user);

    $kinds = collect($result['issues'])->pluck('kind');
    expect($kinds)->toContain('load_error')
        ->and($kinds)->toContain('kpi_error')
        ->and(collect($result['issues'])->every(fn ($i) => $i['repairable'] === false))->toBeTrue();
});
