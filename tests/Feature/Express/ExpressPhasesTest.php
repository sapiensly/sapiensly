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

it('overrides a model fit_check that skipped clearly on-topic tools', function () {
    // Prod yuhunps: GLM answered fit_check and picked TICKET tools for a
    // "dashboard de nps" while the source exposes get-nps-* tools. The
    // deterministic backstop prefers the on-topic tools the model ignored.
    ExpressGateAgent::fake([[
        'tools' => ['get-tickets-time-series-yuhu-tool'], // the model's wrong pick
        'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => [],
    ]]);

    $ctx = xph_ctx($this, 'crea un dashboard del nps de yuhu');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = [
        ['name' => 'get-nps-time-series-yuhu-tool', 'description' => 'NPS semanal de Yuhu', 'input_schema' => []],
        ['name' => 'get-tickets-time-series-yuhu-tool', 'description' => 'Tickets semanales de Yuhu', 'input_schema' => []],
        ['name' => 'search-tickets-yuhu-tool', 'description' => 'Buscar tickets de Yuhu', 'input_schema' => []],
    ];

    (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));

    expect($ctx->chosenTools)->toBe(['get-nps-time-series-yuhu-tool']); // corrected to the nps tool
});

it('leaves a model fit_check pick alone when it already covers the topic', function () {
    // The model DID pick an nps tool — don't second-guess a correct selection.
    ExpressGateAgent::fake([[
        'tools' => ['get-nps-by-dimension-yuhu-tool'],
        'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => [],
    ]]);

    $ctx = xph_ctx($this, 'crea un dashboard del nps de yuhu');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = [
        ['name' => 'get-nps-time-series-yuhu-tool', 'description' => 'NPS semanal', 'input_schema' => []],
        ['name' => 'get-nps-by-dimension-yuhu-tool', 'description' => 'NPS por dimensión', 'input_schema' => []],
        ['name' => 'search-tickets-yuhu-tool', 'description' => 'Buscar tickets', 'input_schema' => []],
    ];

    (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));

    expect($ctx->chosenTools)->toBe(['get-nps-by-dimension-yuhu-tool']); // untouched
});

it('a defaulted fit_check drops org-name-only matches and stays on topic', function () {
    // Prod nps_glm_dsh_1: GLM let the gate default; every tool carries the org
    // name "yuhu", so scoring on it pulled ticket tools into an NPS build. The
    // conservative fallback drops the ubiquitous org word and scores on "nps".
    ExpressGateAgent::fake([
        fn () => throw new RuntimeException('down'),
        fn () => throw new RuntimeException('down'),
    ]);

    $ctx = xph_ctx($this, 'crea un dashboard para analizar el nps de yuhu');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = [
        ['name' => 'get-nps-time-series-yuhu-tool', 'description' => 'NPS semanal de Yuhu', 'input_schema' => []],
        ['name' => 'get-tickets-time-series-yuhu-tool', 'description' => 'Tickets semanales de Yuhu', 'input_schema' => []],
        ['name' => 'search-tickets-yuhu-tool', 'description' => 'Buscar tickets de Yuhu', 'input_schema' => []],
    ];

    (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));

    expect($ctx->chosenTools)->toBe(['get-nps-time-series-yuhu-tool']); // ticket tools dropped
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

it('acquire_objects survives a tool that THROWS — noted and skipped, not fatal', function () {
    // Prod: authoring "tickets" from the source threw (slow/oversized). Without
    // a per-tool guard the whole build died; now the bad tool is skipped and the
    // survivor still banks.
    $authoring = Mockery::mock(ConnectedObjectAuthoring::class);
    $object = xph_object('tickets_semanales', $this->integration->id);
    $authoring->shouldReceive('author')
        ->twice()
        ->andReturnUsing(
            fn () => throw new RuntimeException('source read exploded'),
            fn () => ['ok' => true, 'object' => $object, 'rows' => xph_rows(), 'clamped' => [], 'date_field_ids' => [], 'summary' => 'Creé «Tickets Semanales»'],
        );

    $ctx = xph_ctx($this);
    $ctx->integration = $this->integration;
    $ctx->chosenTools = ['get-tickets-time-series-tool', 'get-orders-tool'];

    (new AcquireObjectsPhase($authoring, app(AppManifestService::class)))->run($ctx, xph_run($this));

    expect($ctx->objects)->toHaveCount(1)
        ->and(implode(' ', $ctx->notes))->toContain('source read exploded');
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

it('picks the time-series as primary over a fields-heavy mode:latest sample', function () {
    // Prod nps_fable_dsh: a 20-record `mode:latest` comments object (18 fields)
    // beat the 5,202-row weekly series (10 fields) on field count and became
    // the primary — so the whole board headlined a one-day sample.
    $mk = fn (string $s) => 'fld_'.strtolower((string) Str::ulid()).$s;
    $series = xph_object('nps_semanal', $this->integration->id); // collection "series" → time_series

    // A comments-like RAW object: a date, many string fields, mode:latest.
    $cid = ['dt' => $mk('a'), 'nps' => $mk('b'), 'seg' => $mk('c'), 'fam' => $mk('d'), 'ver' => $mk('e'), 'acc' => $mk('f'), 'com' => $mk('g')];
    $comments = [
        'id' => 'obj_'.strtolower((string) Str::ulid()),
        'slug' => 'nps_comments', 'name' => 'Nps Comments',
        'fields' => [
            ['id' => $cid['dt'], 'slug' => 'responded_at', 'name' => 'Responded', 'type' => 'datetime'],
            ['id' => $cid['nps'], 'slug' => 'nps', 'name' => 'Nps', 'type' => 'number'],
            ['id' => $cid['seg'], 'slug' => 'segment', 'name' => 'Segment', 'type' => 'string'],
            ['id' => $cid['fam'], 'slug' => 'family', 'name' => 'Family', 'type' => 'string'],
            ['id' => $cid['ver'], 'slug' => 'vertical', 'name' => 'Vertical', 'type' => 'string'],
            ['id' => $cid['acc'], 'slug' => 'account_name', 'name' => 'Account', 'type' => 'string'],
            ['id' => $cid['com'], 'slug' => 'comment', 'name' => 'Comment', 'type' => 'string'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => $this->integration->id,
            'operations' => ['list' => ['mcp_tool' => 'get-nps-comments-tool', 'arguments' => ['mode' => 'latest'], 'collection_path' => 'comments']],
            'field_map' => [],
        ],
    ];

    $ctx = xph_ctx($this);
    $ctx->objects = [$comments, $series]; // comments first + more fields
    $ctx->rowsByObject[$series['id']] = xph_rows();
    $ctx->rowsByObject[$comments['id']] = [['responded_at' => now()->toIso8601String(), 'nps' => 9, 'segment' => 'promoter']];

    app()->call(function (SuggestSpecPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });

    // The weekly series wins the headline; the capped sample is demoted.
    expect($ctx->spec['object_slug'])->toBe('nps_semanal');
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

it('the insight fallback gives each card a DISTINCT real fact, not one filler line', function () {
    // When voice_insights defaults, every card used to get the same "Registros
    // analizados: N" stamp (on a weekly series, N is just the bucket count).
    // Now each card draws a distinct computed fact: a measure avg, a rate, a
    // category concentration.
    ExpressGateAgent::fake([
        ['accept' => true, 'overrides' => []], // overrides accepted as-is
        fn () => throw new RuntimeException('down'), // voice_insights defaults…
        fn () => throw new RuntimeException('down'),
    ]);

    $ctx = xph_ctx($this);
    $object = xph_object('tickets_semanales', $this->integration->id);
    $ctx->objects = [$object];
    $ctx->rowsByObject[$object['id']] = xph_rows();

    app()->call(fn (SuggestSpecPhase $p) => $p->run($ctx, xph_run($this)));
    app()->call(fn (SemanticGatesPhase $p) => $p->run($ctx, xph_run($this)));

    $all = collect($ctx->semantic['insights'])->pluck('body')->implode(' || ');
    expect($all)->toContain('promedio')             // a numeric measure fact
        ->and($all)->toContain('% de los registros') // a boolean rate fact
        ->and($all)->toContain('concentra')          // a category concentration fact
        ->and($all)->not->toContain('Registros analizados'); // no generic filler
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

it('halts on an off-domain request even when the gate skips the piece mapping', function () {
    // The prod miss: a 44-token fit-check answer with pieces=[] bypassed the
    // mapping backstop, and the topic veto was defeated by ONE shared generic
    // word ("producto") — a finance board got built from delivery data and the
    // judge correctly scored it 1/5. With no mapping, the pieces are derived
    // from the request's own fragments and the same majority rule applies.
    ExpressGateAgent::fake([[
        'tools' => ['get-global-otd-time-series-tool'],
        'pieces' => [],
        'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => [],
    ]]);

    $ctx = xph_ctx($this, 'Crea un dashboard de finanzas corporativas con revenue mensual, margen por producto y proyección de flujo de caja.');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = [
        ['name' => 'get-global-otd-time-series-tool', 'description' => 'Serie semanal de productos entregados a tiempo (OTD)', 'input_schema' => []],
        ['name' => 'get-orders-tool', 'description' => 'Orders list', 'input_schema' => []],
    ];

    try {
        (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));
        $this->fail('Expected ExpressHalt');
    } catch (ExpressHalt $halt) {
        expect($halt->status)->toBe('halted_unanswerable')
            ->and($halt->userMessage)->toContain('flujo de caja');
    }
});

it('does not halt an on-domain request whose fragments are analytical phrasing', function () {
    // "métricas clave, tendencias y desgloses relevantes" describes ANY
    // dashboard — those fragments must not read as unanswered data asks.
    ExpressGateAgent::fake([[
        'tools' => ['get-tickets-time-series-tool'],
        'pieces' => [],
        'substitutions' => [], 'unanswerable' => [], 'core_unanswerable' => false, 'alternatives' => [],
    ]]);

    $ctx = xph_ctx($this, 'Crea un dashboard ejecutivo de análisis de tickets: métricas clave, tendencias, desgloses relevantes y filtro de fecha.');
    $ctx->integration = $this->integration;
    $ctx->catalogTools = xph_catalog_tools();

    (new FitCheckPhase(app(GateRunner::class)))->run($ctx, xph_run($this));

    expect($ctx->chosenTools)->toBe(['get-tickets-time-series-tool']);
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

it('composes a multi-object board: the secondary series renders with its own object_id', function () {
    ExpressGateAgent::fake([
        ['accept' => true, 'overrides' => []],
        fn ($prompt) => [
            'title' => 'NPS y Tickets',
            'purpose' => 'Dirección.',
            'insights' => collect(json_decode($prompt, true)['tarjetas_sugeridas'])
                ->map(fn ($c) => ['variant' => $c['variant'], 'title' => $c['title'], 'body' => 'Dato real.'])
                ->values()->all(),
        ],
    ]);

    $primary = xph_object('tickets_semanales', $this->integration->id);
    $mk = fn (string $suffix) => 'fld_'.strtolower((string) Str::ulid()).$suffix;
    $ids = ['date' => $mk('x'), 'nps' => $mk('y')];
    $series = [
        'id' => 'obj_'.strtolower((string) Str::ulid()),
        'slug' => 'nps_semanal',
        'name' => 'NPS Semanal',
        'fields' => [
            ['id' => $ids['date'], 'slug' => 'bucket_start', 'name' => 'Semana', 'type' => 'date'],
            ['id' => $ids['nps'], 'slug' => 'nps', 'name' => 'NPS', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'integration_id' => $this->integration->id,
            'operations' => ['list' => ['mcp_tool' => 'get-nps-series-tool', 'collection_path' => 'series']],
            'field_map' => [
                ['field_id' => $ids['date'], 'external_path' => 'bucket_start'],
                ['field_id' => $ids['nps'], 'external_path' => 'nps'],
            ],
        ],
    ];

    app(AppManifestService::class)->applyPatch(
        $this->testApp->fresh(),
        [['op' => 'add', 'path' => '/objects/-', 'value' => $primary], ['op' => 'add', 'path' => '/objects/-', 'value' => $series]],
        $this->user, 'objs',
    );

    $ctx = xph_ctx($this, 'dashboard de tickets y evolución del nps');
    $ctx->objects = [$primary, $series];
    $ctx->rowsByObject[$primary['id']] = xph_rows();
    $ctx->rowsByObject[$series['id']] = collect(range(0, 3))->map(fn (int $i) => [
        'bucket_start' => now()->utc()->subWeeks($i)->toDateString(), 'nps' => 40 + $i,
    ])->all();

    app()->call(function (SuggestSpecPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });
    app()->call(function (SemanticGatesPhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });
    app()->call(function (CompilePhase $phase) use ($ctx) {
        $phase->run($ctx, xph_run($this));
    });

    expect($ctx->page)->not->toBeNull();

    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->fresh());
    $page = collect($manifest['pages'])->firstWhere('slug', $ctx->page['slug']);
    $objectIdsRead = collect($page['blocks'])->where('type', 'container')
        ->flatMap(fn (array $c) => $c['blocks'])->where('type', 'chart')
        ->pluck('data_source.object_id')->unique()->values();

    // BOTH objects render — before this, secondaries were acquired and ignored.
    expect($objectIdsRead)->toContain($primary['id'])
        ->and($objectIdsRead)->toContain($series['id']);

    // The secondary facts reached the insight gate's prompt material.
    expect(json_encode($ctx->facts, JSON_UNESCAPED_UNICODE))->toContain('objetos_secundarios');
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
