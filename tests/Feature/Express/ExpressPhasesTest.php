<?php

use App\Ai\ExpressGateAgent;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\Integration;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Connected\IntegrationCatalog;
use App\Services\Express\ExpressContext;
use App\Services\Express\ExpressHalt;
use App\Services\Express\ExpressPipeline;
use App\Services\Express\GateRunner;
use App\Services\Express\Phases\AcquireObjectsPhase;
use App\Services\Express\Phases\CompilePhase;
use App\Services\Express\Phases\FitCheckPhase;
use App\Services\Express\Phases\ResolveSourcePhase;
use App\Services\Express\Phases\SemanticGatesPhase;
use App\Services\Express\Phases\SuggestSpecPhase;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->conv = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'active',
    ]);
    app(AppManifestService::class)->createVersion($this->testApp, [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'xp_'.strtolower(Str::random(6)),
        'name' => 'Express',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'settings' => ['default_locale' => 'es-MX'],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $this->user);

    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1',
        'is_mcp' => true,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'active',
    ]);
});

function xph_ctx($test, string $prompt = 'dashboard de análisis de tickets semanales'): ExpressContext
{
    return new ExpressContext($test->testApp->fresh(), $test->user, $test->conv, $prompt);
}

function xph_run($test): PipelineRun
{
    return PipelineRun::create(['app_id' => $test->testApp->id, 'conversation_id' => $test->conv->id, 'prompt' => 'p']);
}

function xph_catalog_tools(): array
{
    return [
        ['name' => 'get-tickets-time-series-tool', 'description' => 'Weekly aggregated tickets series', 'input_schema' => []],
        ['name' => 'get-orders-tool', 'description' => 'Orders list', 'input_schema' => []],
    ];
}

/** A valid connected-object node like ConnectedObjectAuthoring produces. */
function xph_object(string $slug, string $integrationId): array
{
    $mk = fn (string $suffix) => 'fld_'.strtolower((string) Str::ulid()).$suffix;
    $ids = ['date' => $mk('a'), 'cat' => $mk('b'), 'num' => $mk('c'), 'flag' => $mk('d')];

    return [
        'id' => 'obj_'.strtolower((string) Str::ulid()),
        'slug' => $slug,
        'name' => Str::headline($slug),
        'fields' => [
            ['id' => $ids['date'], 'slug' => 'bucket_start', 'name' => 'Semana', 'type' => 'date'],
            ['id' => $ids['cat'], 'slug' => 'categoria', 'name' => 'Categoría', 'type' => 'string'],
            ['id' => $ids['num'], 'slug' => 'total', 'name' => 'Total', 'type' => 'number'],
            ['id' => $ids['flag'], 'slug' => 'sla', 'name' => 'SLA Incumplido', 'type' => 'boolean'],
        ],
        'source' => [
            'type' => 'connected',
            'integration_id' => $integrationId,
            'id_path' => 'id',
            'operations' => ['list' => ['mcp_tool' => 'get-tickets-time-series-tool', 'collection_path' => 'series']],
            'field_map' => [
                ['field_id' => $ids['date'], 'external_path' => 'bucket_start'],
                ['field_id' => $ids['cat'], 'external_path' => 'categoria'],
                ['field_id' => $ids['num'], 'external_path' => 'total'],
                ['field_id' => $ids['flag'], 'external_path' => 'sla'],
            ],
        ],
    ];
}

function xph_rows(): array
{
    return [
        ['id' => 'W1', 'bucket_start' => now()->utc()->subDays(3)->toDateString(), 'categoria' => 'Envíos', 'total' => 12, 'sla' => true],
        ['id' => 'W2', 'bucket_start' => now()->utc()->subDays(10)->toDateString(), 'categoria' => 'Pagos', 'total' => 7, 'sla' => false],
        ['id' => 'W3', 'bucket_start' => now()->utc()->subDays(17)->toDateString(), 'categoria' => 'Envíos', 'total' => 9, 'sla' => false],
    ];
}

it('resolve_source picks the authorized MCP integration and loads its catalog', function () {
    $catalog = Mockery::mock(IntegrationCatalog::class);
    $catalog->shouldReceive('knownShapes')->andReturn([]);
    $catalog->shouldReceive('tools')->andReturn(xph_catalog_tools());

    $ctx = xph_ctx($this);
    (new ResolveSourcePhase($catalog))->run($ctx, xph_run($this));

    expect($ctx->integration?->id)->toBe($this->integration->id)
        ->and($ctx->catalogTools)->toHaveCount(2);
});

it('resolve_source halts with a human message when no MCP source exists', function () {
    $this->integration->delete();
    $catalog = Mockery::mock(IntegrationCatalog::class);

    (new ResolveSourcePhase($catalog))->run(xph_ctx($this), xph_run($this));
})->throws(ExpressHalt::class, 'conexión MCP');

it('fit_check chooses tools and records honest substitutions', function () {
    ExpressGateAgent::fake([[
        'tools' => ['get-tickets-time-series-tool'],
        'substitutions' => [['asked' => 'CSAT', 'using' => 'SLA incumplidos', 'reason' => 'no hay campo CSAT']],
        'unanswerable' => [],
        'core_unanswerable' => false,
        'alternatives' => [],
    ]]);

    $ctx = xph_ctx($this);
    $ctx->integration = $this->integration;
    $ctx->catalogTools = xph_catalog_tools();

    (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));

    expect($ctx->chosenTools)->toBe(['get-tickets-time-series-tool'])
        ->and($ctx->substitutions[0]['using'])->toBe('SLA incumplidos')
        ->and(implode(' ', $ctx->notes))->toContain('Sustitución');
});

it('fit_check halts proposing alternatives when the core is unanswerable', function () {
    ExpressGateAgent::fake([[
        'tools' => [], 'substitutions' => [], 'unanswerable' => [],
        'core_unanswerable' => true,
        'alternatives' => ['Dashboard de volumen semanal de tickets', 'Dashboard de SLA'],
    ]]);

    $ctx = xph_ctx($this, 'dashboard financiero con flujo de caja');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = xph_catalog_tools();

    try {
        (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));
        $this->fail('Expected ExpressHalt');
    } catch (ExpressHalt $halt) {
        expect($halt->status)->toBe('halted_unanswerable')
            ->and($halt->userMessage)->toContain('volumen semanal');
    }
});

it('fit_check degrades to keyword matching when the gate fails twice', function () {
    ExpressGateAgent::fake([
        fn () => throw new RuntimeException('down'),
        fn () => throw new RuntimeException('down'),
    ]);

    $ctx = xph_ctx($this, 'quiero analizar los tickets por semana');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = xph_catalog_tools();

    (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));

    expect($ctx->chosenTools)->toBe(['get-tickets-time-series-tool']);
});

it('acquire_objects banks the survivors as ONE version and notes the failures', function () {
    $authoring = Mockery::mock(ConnectedObjectAuthoring::class);
    $object = xph_object('tickets_semanales', $this->integration->id);
    $authoring->shouldReceive('author')
        ->twice()
        ->andReturn(
            ['ok' => true, 'object' => $object, 'rows' => xph_rows(), 'clamped' => [], 'date_field_ids' => [], 'summary' => 'Creé «Tickets Semanales»'],
            ['ok' => false, 'error' => 'tool timed out'],
        );

    $ctx = xph_ctx($this);
    $ctx->integration = $this->integration;
    $ctx->chosenTools = ['get-tickets-time-series-tool', 'get-orders-tool'];

    (new AcquireObjectsPhase($authoring, app(AppManifestService::class)))->run($ctx, xph_run($this));

    expect($ctx->objects)->toHaveCount(1)
        ->and($ctx->rowsByObject[$object['id']])->toHaveCount(3)
        ->and(implode(' ', $ctx->notes))->toContain('tool timed out');

    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    expect(collect($manifest['objects'])->pluck('slug'))->toContain('tickets_semanales');
});

it('suggest_spec derives the spec + facts from the primary object', function () {
    $ctx = xph_ctx($this);
    $object = xph_object('tickets_semanales', $this->integration->id);
    $ctx->objects = [$object];
    $ctx->rowsByObject[$object['id']] = xph_rows();

    app()->call(function (SuggestSpecPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });

    expect($ctx->spec['object_slug'])->toBe('tickets_semanales')
        ->and($ctx->spec['kpis'])->not->toBeEmpty()
        ->and($ctx->spec['charts'])->not->toBeEmpty()
        ->and($ctx->facts['row_count'])->toBe(3);
});

it('semantic gates fill voice and factual insights, judging overrides deterministically', function () {
    // Overrides candidate references a nonexistent field → the judge must
    // reject it and keep the suggestion as-is.
    ExpressGateAgent::fake([
        ['accept' => false, 'overrides' => ['charts' => [['label' => 'X', 'chart_type' => 'line', 'aggregation' => 'count', 'x_field_id' => 'fld_nope']]]],
        fn ($prompt) => [
            'title' => 'Panel Ejecutivo de Tickets',
            'purpose' => 'Dirección: volumen y SLA.',
            'insights' => collect(json_decode($prompt, true)['tarjetas_sugeridas'])
                ->map(fn ($c) => ['variant' => $c['variant'], 'title' => $c['title'], 'body' => 'El 66% del volumen viene de Envíos.'])
                ->values()->all(),
        ],
    ]);

    $ctx = xph_ctx($this);
    $object = xph_object('tickets_semanales', $this->integration->id);
    $ctx->objects = [$object];
    $ctx->rowsByObject[$object['id']] = xph_rows();

    app()->call(function (SuggestSpecPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });
    app()->call(function (SemanticGatesPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });

    expect($ctx->semantic['overrides'])->toBe([])   // judged out
        ->and($ctx->semantic['voice']['title'])->toBe('Panel Ejecutivo de Tickets')
        ->and($ctx->semantic['insights'][0]['body'])->toContain('66%');
});

it('compile merges everything, enforces lints and applies the page as a version', function () {
    $ctx = xph_ctx($this);
    $object = xph_object('tickets_semanales', $this->integration->id);

    // Put the object into the real manifest first (as acquire would).
    app(AppManifestService::class)->applyPatch(
        $this->testApp->fresh(),
        [['op' => 'add', 'path' => '/objects/-', 'value' => $object]],
        $this->user, 'obj',
    );

    $ctx->objects = [$object];
    $ctx->rowsByObject[$object['id']] = xph_rows();

    app()->call(function (SuggestSpecPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });
    $ctx->semantic = [
        'overrides' => [],
        'voice' => ['title' => 'Panel Ejecutivo', 'purpose' => 'Dirección: volumen semanal y SLA.'],
        'insights' => $ctx->spec['insights'],
    ];

    app()->call(function (CompilePhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });

    expect($ctx->page['name'])->toBe('Panel Ejecutivo')
        ->and($ctx->page['path'])->not->toBeEmpty();

    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    $page = collect($manifest['pages'])->firstWhere('slug', $ctx->page['slug']);
    expect($page)->not->toBeNull()
        ->and(json_encode($page))->toContain('range_start')
        ->and(json_encode($page))->toContain('metric_grid');
});

it('runs the whole chain end-to-end through ExpressPipeline', function () {
    $catalog = Mockery::mock(IntegrationCatalog::class);
    $catalog->shouldReceive('knownShapes')->andReturn([]);
    $catalog->shouldReceive('tools')->andReturn(xph_catalog_tools());

    $object = xph_object('tickets_semanales', $this->integration->id);
    $authoring = Mockery::mock(ConnectedObjectAuthoring::class);
    $authoring->shouldReceive('author')->once()->andReturn(
        ['ok' => true, 'object' => $object, 'rows' => xph_rows(), 'clamped' => [], 'date_field_ids' => [], 'summary' => 'Creé «Tickets Semanales»'],
    );

    ExpressGateAgent::fake([
        ['tools' => ['get-tickets-time-series-tool'], 'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => []],
        ['accept' => true, 'overrides' => []],
        fn ($prompt) => [
            'title' => 'Panel de Tickets',
            'purpose' => 'Volumen y SLA por semana.',
            'insights' => collect(json_decode($prompt, true)['tarjetas_sugeridas'])
                ->map(fn ($c) => ['variant' => $c['variant'], 'title' => $c['title'], 'body' => 'Hecho real.'])
                ->values()->all(),
        ],
    ]);

    $ctx = xph_ctx($this);
    $run = xph_run($this);

    $phases = [
        new ResolveSourcePhase($catalog),
        app()->make(FitCheckPhase::class),
        new AcquireObjectsPhase($authoring, app(AppManifestService::class)),
        app()->make(SuggestSpecPhase::class),
        app()->make(SemanticGatesPhase::class),
        app()->make(CompilePhase::class),
    ];

    $result = app(ExpressPipeline::class)->execute($run, $ctx, $phases);

    expect($result->status)->toBe('succeeded')
        ->and($result->result['page']['name'])->toBe('Panel de Tickets')
        ->and($result->gates)->toHaveKeys(['fit_check', 'spec_overrides', 'voice_insights']);

    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    expect($manifest['pages'])->toHaveCount(1)
        ->and($manifest['objects'])->toHaveCount(1);
});

it('fit_check vetoes tools known to return no rows, even if the model picks them', function () {
    ExpressGateAgent::fake([[
        'tools' => ['get-tickets-overview-tool', 'get-tickets-time-series-tool'],
        'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => [],
    ]]);

    $ctx = xph_ctx($this);
    $ctx->integration = $this->integration;
    $ctx->catalogTools = array_merge(xph_catalog_tools(), [
        ['name' => 'get-tickets-overview-tool', 'description' => 'Totals summary', 'input_schema' => []],
    ]);
    // Observed on a previous run: overview returns no rows (summary-only).
    $ctx->knownShapes = ['get-tickets-overview-tool' => ['fields' => []]];

    (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));

    expect($ctx->chosenTools)->toBe(['get-tickets-time-series-tool']);
});

it('the heuristic fallback also skips no-rows tools', function () {
    ExpressGateAgent::fake([
        fn () => throw new RuntimeException('down'),
        fn () => throw new RuntimeException('down'),
    ]);

    $ctx = xph_ctx($this, 'analiza los tickets');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = [
        ['name' => 'get-tickets-overview-tool', 'description' => 'tickets totals', 'input_schema' => []],
        ['name' => 'get-tickets-time-series-tool', 'description' => 'tickets weekly series', 'input_schema' => []],
    ];
    $ctx->knownShapes = ['get-tickets-overview-tool' => ['fields' => []]];

    (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));

    expect($ctx->chosenTools)->not->toContain('get-tickets-overview-tool')
        ->and($ctx->chosenTools)->toContain('get-tickets-time-series-tool');
});

it('halts when the model itself cannot map most requested pieces to any tool', function () {
    // The stochastic-bool failure: core_unanswerable=false but the piece
    // mapping shows 3 of 3 pieces unmapped — the server decides, not the bool.
    ExpressGateAgent::fake([[
        'tools' => ['get-orders-tool'],
        'pieces' => [
            ['asked' => 'revenue mensual', 'tool' => null],
            ['asked' => 'margen por producto', 'tool' => null],
            ['asked' => 'flujo de caja', 'tool' => null],
        ],
        'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => [],
    ]]);

    $ctx = xph_ctx($this, 'dashboard financiero con revenue mensual, margen y flujo de caja');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = xph_catalog_tools();

    try {
        (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));
        $this->fail('Expected ExpressHalt');
    } catch (ExpressHalt $halt) {
        expect($halt->status)->toBe('halted_unanswerable')
            ->and($halt->userMessage)->toContain('revenue mensual');
    }
});

it('halts when every chosen tool is off-topic for the request', function () {
    // The observed filler board: a "financial projection" built from delivery
    // (OTD) tools — zero topical overlap between request and chosen tools.
    ExpressGateAgent::fake([[
        'tools' => ['get-orders-tool'],
        'pieces' => [['asked' => 'revenue mensual', 'tool' => 'get-orders-tool']],
        'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => [],
    ]]);

    $ctx = xph_ctx($this, 'dashboard financiero de revenue, margen y proyección de caja');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = xph_catalog_tools();

    try {
        (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));
        $this->fail('Expected ExpressHalt');
    } catch (ExpressHalt $halt) {
        expect($halt->status)->toBe('halted_unanswerable')
            ->and($halt->userMessage)->toContain('no tratan el tema');
    }
});

it('declared proxies in the piece mapping become honest substitutions', function () {
    ExpressGateAgent::fake([[
        'tools' => ['get-tickets-time-series-tool'],
        'pieces' => [
            ['asked' => 'volumen de tickets', 'tool' => 'get-tickets-time-series-tool'],
            ['asked' => 'CSAT promedio', 'tool' => null, 'proxy' => 'SLA incumplidos'],
        ],
        'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => [],
    ]]);

    $ctx = xph_ctx($this, 'dashboard de tickets con volumen y CSAT');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = xph_catalog_tools();

    (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));

    expect($ctx->chosenTools)->toBe(['get-tickets-time-series-tool'])
        ->and($ctx->substitutions[0]['asked'])->toBe('CSAT promedio')
        ->and($ctx->substitutions[0]['using'])->toBe('SLA incumplidos');
});

it('clamps a rambling model title so the compile never dies on maxLength', function () {
    // The prod sla failure: a 1,207-char paragraph AS the title → page name
    // maxLength 100 violated → whole build failed.
    ExpressGateAgent::fake([
        ['accept' => true, 'overrides' => []],
        fn ($prompt) => [
            'title' => str_repeat('Dashboard de Calidad de Servicio con Cumplimiento SLA ', 25)."\nsegunda línea",
            'purpose' => str_repeat('propósito muy largo ', 40),
            'insights' => collect(json_decode($prompt, true)['tarjetas_sugeridas'])
                ->map(fn ($c) => ['variant' => $c['variant'], 'title' => str_repeat('t', 300), 'body' => str_repeat('b', 2000)])
                ->values()->all(),
        ],
    ]);

    $ctx = xph_ctx($this);
    $object = xph_object('tickets_semanales', $this->integration->id);
    app(AppManifestService::class)->applyPatch(
        $this->testApp->fresh(),
        [['op' => 'add', 'path' => '/objects/-', 'value' => $object]],
        $this->user, 'obj',
    );
    $ctx->objects = [$object];
    $ctx->rowsByObject[$object['id']] = xph_rows();

    app()->call(function (SuggestSpecPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });
    app()->call(function (SemanticGatesPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });
    app()->call(function (CompilePhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });

    expect($ctx->page)->not->toBeNull()
        ->and(mb_strlen($ctx->page['name']))->toBeLessThanOrEqual(96)
        ->and($ctx->page['name'])->not->toContain("\n");
});

it('formats object alternatives in the halt message as readable lines', function () {
    ExpressGateAgent::fake([[
        'tools' => [], 'substitutions' => [], 'unanswerable' => [],
        'core_unanswerable' => true,
        'alternatives' => [
            ['dashboard' => 'Dashboard de Órdenes de Venta', 'relevancia' => 'lo más cercano a revenue'],
            'Dashboard de Entregas OTD',
        ],
    ]]);

    $ctx = xph_ctx($this, 'dashboard financiero');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = xph_catalog_tools();

    try {
        (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));
        $this->fail('Expected ExpressHalt');
    } catch (ExpressHalt $halt) {
        expect($halt->userMessage)->toContain('Dashboard de Órdenes de Venta — lo más cercano a revenue')
            ->and($halt->userMessage)->toContain('Dashboard de Entregas OTD')
            ->and($halt->userMessage)->not->toContain('{"dashboard"');
    }
});

it('does not halt an NPS request when the source has NPS tools (3-letter acronyms count)', function () {
    // The prod false positive: "analizar el comportamiento del nps de yuhu" —
    // 'nps' (3 letters) was dropped from the topic words, so the correctly
    // chosen get-nps-* tools scored zero overlap and the backstop killed a
    // legitimate build.
    ExpressGateAgent::fake([[
        'tools' => ['get-nps-time-series-tool'],
        'pieces' => [['asked' => 'comportamiento del NPS', 'tool' => 'get-nps-time-series-tool']],
        'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => [],
    ]]);

    $ctx = xph_ctx($this, 'crea un dashboard que permita analizar el comportamiento del nps de yuhu');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = [
        ['name' => 'get-nps-time-series-tool', 'description' => 'NPS survey scores over time', 'input_schema' => []],
        ['name' => 'get-orders-tool', 'description' => 'Orders list', 'input_schema' => []],
    ];

    (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));

    expect($ctx->chosenTools)->toBe(['get-nps-time-series-tool']);
});

it('titles are sanitized: no markup junk, cut at word boundaries', function () {
    ExpressGateAgent::fake([
        ['accept' => true, 'overrides' => []],
        fn ($prompt) => [
            'title' => 'Dashboard de NPS: Get NPS Comments Analysis & Diagnostics for <target_audience> con métricas extendidas de comportamiento y diagnóstico',
            'purpose' => 'Dirección.',
            'insights' => collect(json_decode($prompt, true)['tarjetas_sugeridas'])
                ->map(fn ($c) => ['variant' => $c['variant'], 'title' => $c['title'], 'body' => 'Dato.'])
                ->values()->all(),
        ],
    ]);

    $ctx = xph_ctx($this);
    $object = xph_object('tickets_semanales', $this->integration->id);
    app(AppManifestService::class)->applyPatch(
        $this->testApp->fresh(),
        [['op' => 'add', 'path' => '/objects/-', 'value' => $object]],
        $this->user, 'obj',
    );
    $ctx->objects = [$object];
    $ctx->rowsByObject[$object['id']] = xph_rows();

    app()->call(function (SuggestSpecPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });
    app()->call(function (SemanticGatesPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });
    app()->call(function (CompilePhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });

    expect($ctx->page['name'])->not->toContain('<')
        ->and($ctx->page['name'])->not->toContain('>')
        ->and(mb_strlen($ctx->page['name']))->toBeLessThanOrEqual(96)
        // Word-boundary cut: never ends mid-word like "targe…".
        ->and($ctx->page['name'])->toMatch('/(\s|\S{2,})…$|[^…]$/u');
});
