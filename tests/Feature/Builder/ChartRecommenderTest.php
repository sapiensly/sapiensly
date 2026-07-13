<?php

use App\Models\App;
use App\Models\Integration;
use App\Models\Record;
use App\Models\User;
use App\Services\Analyst\AnalystCore;
use App\Services\Analyst\CrossSourceAnalyzer;
use App\Services\Analyst\DataQualityCheck;
use App\Services\Analyst\DerivedMetricProposer;
use App\Services\Analyst\DomainClassifier;
use App\Services\Analyst\RecommendationNarrator;
use App\Services\Builder\ChartRecommender;
use App\Services\Connected\ConnectedIntegrationResolver;
use App\Services\Connected\ConnectedObjectReader;
use App\Services\Express\ComputedFactsBuilder;
use App\Services\Express\SemanticProfile;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\ObjectRowSource;
use App\Support\Tenancy\TenantContext;

/** A connected ticket-breakdown object: reason (string) + total_tickets (additive). */
function recObject(): array
{
    return [
        'id' => 'obj_rec00000000',
        'slug' => 'tickets_reason_breakdown',
        'name' => 'Tickets Reason Breakdown',
        'fields' => [
            ['id' => 'fld_reason00000', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_total000000', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'integration_id' => 'integ_rec000000',
            'field_map' => [
                ['field_id' => 'fld_reason00000', 'external_path' => 'reason'],
                ['field_id' => 'fld_total000000', 'external_path' => 'total'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'get-reasons', 'collection_path' => 'reasons']],
        ],
    ];
}

/** Rows where a few reasons carry most of the volume → concentration fires. */
function concentratedRows(): array
{
    return [
        ['reason' => 'Envíos', 'total' => 412],
        ['reason' => 'Cobranza', 'total' => 286],
        ['reason' => 'Garantías', 'total' => 96],
        ['reason' => 'Precompra', 'total' => 74],
        ['reason' => 'Créditos', 'total' => 52],
        ['reason' => 'Devoluciones', 'total' => 29],
        ['reason' => 'Otros', 'total' => 15],
    ];
}

/** Two connected sources sharing the reason dimension: volume + FCR. */
function volumeObject(): array
{
    return [
        'id' => 'obj_vol0000000', 'slug' => 'tickets_by_reason', 'name' => 'Tickets By Reason',
        'fields' => [
            ['id' => 'fld_vreason000', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_vtotal0000', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'integ_v00000000',
            'field_map' => [
                ['field_id' => 'fld_vreason000', 'external_path' => 'reason'],
                ['field_id' => 'fld_vtotal0000', 'external_path' => 'total'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'get-v', 'collection_path' => 'rows']],
        ],
    ];
}
function perfObject(): array
{
    return [
        'id' => 'obj_perf000000', 'slug' => 'fcr_by_reason', 'name' => 'Fcr By Reason',
        'fields' => [
            ['id' => 'fld_preason000', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_pfcr000000', 'slug' => 'fcr_pct', 'name' => 'Fcr Pct', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'integ_p00000000',
            'field_map' => [
                ['field_id' => 'fld_preason000', 'external_path' => 'reason'],
                ['field_id' => 'fld_pfcr000000', 'external_path' => 'fcr'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'get-p', 'collection_path' => 'rows']],
        ],
    ];
}
function volRows(): array
{
    return [
        ['reason' => 'A', 'total' => 400], ['reason' => 'B', 'total' => 300],
        ['reason' => 'C', 'total' => 200], ['reason' => 'D', 'total' => 100], ['reason' => 'E', 'total' => 50],
    ];
}
function perfRows(): array
{
    return [
        ['reason' => 'A', 'fcr' => 60], ['reason' => 'B', 'fcr' => 65],
        ['reason' => 'C', 'fcr' => 80], ['reason' => 'D', 'fcr' => 85], ['reason' => 'E', 'fcr' => 90],
    ];
}

function makeCore(array $rows): AnalystCore
{
    $source = Mockery::mock(ObjectRowSource::class);
    $source->shouldReceive('sample')->andReturn($rows);

    return new AnalystCore(
        $source,
        new ComputedFactsBuilder,
        new SemanticProfile,
        new DomainClassifier,
        app(RecommendationNarrator::class),
        new DataQualityCheck,
        new CrossSourceAnalyzer(new SemanticProfile),
        new DerivedMetricProposer(new SemanticProfile),
    );
}

function makeRecommender(array $rows): ChartRecommender
{
    return new ChartRecommender(makeCore($rows));
}

it('the core analyses without a page, a block or a cache — any surface can ask', function () {
    // This is what makes the analyst reusable: no manifest page, no blocks, no
    // TenantCache spec. A deck, an agent or MCP calls exactly this.
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    $analysis = makeCore(concentratedRows())->analyze(
        $app,
        ['objects' => [recObject()], 'settings' => ['default_locale' => 'es-MX']],
        $user,
        'es',
    );

    $pareto = collect($analysis['findings'])->firstWhere('kind', 'pareto');
    expect($pareto)->not->toBeNull()
        // A finding carries the analysis, not a card: the spec, the fact, the
        // preview and the keys a surface dedupes by.
        ->and($pareto['chart']['chart_type'])->toBe('pareto')
        ->and($pareto['semantic_key'])->toBe('breakdown|total tickets|reason')
        ->and($pareto['preview']['values'][0])->toBe(412.0)
        ->and($pareto['why'])->toContain('%')
        ->and($analysis['domain']['sector'])->toBe('support');

    // And the surface tells the core what it already shows — by semantic key,
    // not by handing over a page.
    $deduped = makeCore(concentratedRows())->analyze(
        $app,
        ['objects' => [recObject()], 'settings' => ['default_locale' => 'es-MX']],
        $user,
        'es',
        ['breakdown|total tickets|reason'],
    );

    expect(collect($deduped['findings'])->firstWhere('kind', 'pareto'))->toBeNull();

    app(TenantContext::class)->forget();
});

it('classifies a support-ticket board as the support domain', function () {
    $classifier = new DomainClassifier;
    $domain = $classifier->classify([recObject()], 'es');

    expect($domain['sector'])->toBe('support')
        ->and($domain['label'])->toBe('Soporte de tickets')
        // Headline concepts of the domain rank analyses higher.
        ->and($classifier->isHeadline($domain, 'FCR Pct'))->toBeTrue()
        ->and($classifier->isHeadline($domain, 'Nombre del agente'))->toBeFalse();
});

it('recommends a Pareto grounded in the real concentration fact', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);

    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);
    $manifest = ['objects' => [recObject()], 'settings' => ['default_locale' => 'es-MX']];
    $page = ['id' => 'pag_rec0000000', 'slug' => 'dashboard', 'blocks' => []];

    $result = makeRecommender(concentratedRows())->recommend($app, $manifest, $page, $user, 'es');

    expect($result['domain']['sector'])->toBe('support')
        ->and($result['sources'])->toBe(1)
        ->and($result['recommendations'])->not->toBeEmpty();

    $pareto = collect($result['recommendations'])->firstWhere('form', 'pareto');
    expect($pareto)->not->toBeNull()
        ->and($pareto['why'])->toContain('%')                 // carries the real number
        ->and($pareto['preview']['kind'])->toBe('pareto')
        ->and($pareto['preview']['values'][0])->toBe(412.0);  // top category value

    // The proposed spec is cached, so «Agregar» inserts exactly what was shown.
    $spec = makeRecommender(concentratedRows())->specFor($app, $pareto['id']);
    expect($spec)->not->toBeNull()
        ->and($spec['object_id'])->toBe('obj_rec00000000')
        ->and($spec['chart']['chart_type'])->toBe('pareto')
        ->and($spec['chart']['group_by_field_id'])->toBe('fld_reason00000')
        ->and($spec['chart']['y_field_id'])->toBe('fld_total000000');

    app(TenantContext::class)->forget();
});

it('dedupes the same cut across a DIFFERENT overlapping source', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);

    // A second source of the SAME breakdown: field is generically named «Key»,
    // but the object name's suffix says it's the reason cut.
    $objB = [
        'id' => 'obj_recB0000000',
        'slug' => 'tickets_by_dimension_reason',
        'name' => 'Tickets By Dimension · Reason',
        'fields' => [
            ['id' => 'fld_keyB0000000', 'slug' => 'key', 'name' => 'Key', 'type' => 'string'],
            ['id' => 'fld_totalB00000', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'integ_recB00000',
            'field_map' => [
                ['field_id' => 'fld_keyB0000000', 'external_path' => 'reason'],
                ['field_id' => 'fld_totalB00000', 'external_path' => 'total'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'get-x', 'collection_path' => 'rows']],
        ],
    ];

    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);
    $manifest = ['objects' => [recObject(), $objB], 'settings' => ['default_locale' => 'es-MX']];
    // Board already shows Total Tickets by Reason (from source A).
    $page = ['id' => 'pag_rec0000000', 'slug' => 'dashboard', 'blocks' => [[
        'id' => 'blk_existing000', 'type' => 'chart', 'chart_type' => 'pareto',
        'group_by_field_id' => 'fld_reason00000', 'y_field_id' => 'fld_total000000',
        'aggregation' => 'sum', 'data_source' => ['object_id' => 'obj_rec00000000'],
    ]]];

    $result = makeRecommender(concentratedRows())->recommend($app, $manifest, $page, $user, 'es');

    // Source B is a different object, but its «Total Tickets by (reason)»
    // breakdown is the SAME analysis — must not be re-recommended.
    $breakdowns = collect($result['recommendations'])
        ->filter(fn ($r) => in_array($r['form'], ['pareto', 'hbar', 'donut', 'bar'], true));
    expect($breakdowns)->toBeEmpty();

    app(TenantContext::class)->forget();
});

it('data quality flags a stale source and a high-null column', function () {
    $object = [
        'name' => 'Tickets Time Series',
        'fields' => [
            ['id' => 'f1', 'slug' => 'total', 'name' => 'Total', 'type' => 'number'],
            ['id' => 'f2', 'slug' => 'note', 'name' => 'Note', 'type' => 'string'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'f1', 'external_path' => 'total'],
            ['field_id' => 'f2', 'external_path' => 'note'],
        ]],
    ];
    $rows = [
        ['total' => 10, 'note' => ''],
        ['total' => 20, 'note' => ''],
        ['total' => 30, 'note' => 'x'],
    ]; // note = 66% empty
    $facts = ['trend' => ['Bucket Start' => ['span_to' => '2020-01-01']]]; // very stale

    $flags = (new DataQualityCheck)->run(
        ['obj1' => ['object' => $object, 'rows' => $rows, 'facts' => $facts]],
        true,
    );

    expect(collect($flags)->firstWhere('level', 'warn'))->not->toBeNull()
        ->and(collect($flags)->firstWhere('level', 'warn')['text'])->toContain('no actualiza')
        ->and(collect($flags)->firstWhere('level', 'info')['text'])->toContain('vacío');
});

it('does not re-recommend a cut the board already shows', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);

    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);
    $manifest = ['objects' => [recObject()], 'settings' => ['default_locale' => 'es-MX']];
    // The Pareto (reason × total_tickets, sum) is ALREADY on the page.
    $page = ['id' => 'pag_rec0000000', 'slug' => 'dashboard', 'blocks' => [[
        'id' => 'blk_existing000', 'type' => 'chart', 'chart_type' => 'pareto',
        'group_by_field_id' => 'fld_reason00000', 'y_field_id' => 'fld_total000000',
        'aggregation' => 'sum', 'data_source' => ['object_id' => 'obj_rec00000000'],
    ]]];

    $result = makeRecommender(concentratedRows())->recommend($app, $manifest, $page, $user, 'es');

    expect(collect($result['recommendations'])->firstWhere('form', 'pareto'))->toBeNull();

    app(TenantContext::class)->forget();
});

it('exposes recommendations over HTTP and adds one to the board', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    $manifest = [
        'id' => $app->id, 'name' => 'Tickets', 'slug' => 'tickets', 'version' => 1,
        'schema_version' => '1.0.0', 'settings' => ['default_locale' => 'es-MX'],
        'objects' => [recObject()],
        'pages' => [[
            'id' => 'pag_rec0000000', 'slug' => 'dashboard', 'name' => 'Dash', 'path' => '/dashboard',
            'blocks' => [['id' => 'blk_head000000', 'type' => 'heading', 'level' => 3, 'content' => 'Desglose']],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_rec0000000', 'slug' => 'admin', 'name' => 'Admin']]],
        'workflows' => [],
    ];
    app(AppManifestService::class)->createVersion($app, $manifest, $user);

    // Fake the connected reader + integration so the facts come from our rows.
    app()->instance(ConnectedObjectReader::class, tap(Mockery::mock(ConnectedObjectReader::class), fn ($m) => $m->shouldReceive('list')->andReturn(['ok' => true, 'rows' => concentratedRows()])));
    app()->instance(ConnectedIntegrationResolver::class, tap(Mockery::mock(ConnectedIntegrationResolver::class), fn ($m) => $m->shouldReceive('resolve')->andReturn(Mockery::mock(Integration::class))));

    $body = $this->actingAs($user)
        ->getJson("/apps/{$app->id}/builder/recommendations?page=dashboard")
        ->assertOk()
        ->assertJsonPath('domain.sector', 'support')
        ->json();

    $pareto = collect($body['recommendations'])->firstWhere('form', 'pareto');
    expect($pareto)->not->toBeNull()
        // The «fuentes leídas» panel: what each source provides + what to add.
        ->and($body['sources_detail'][0]['name'])->toBe('Tickets Reason Breakdown')
        ->and($body['sources_detail'][0]['measures'])->toContain('Total Tickets')
        ->and($body['sources_detail'][0]['dimensions'])->toContain('Reason')
        ->and($body['source_suggestions'])->not->toBeEmpty()
        ->and($body['source_suggestions'][0])->toHaveKeys(['title', 'why']);

    $this->actingAs($user)
        ->postJson("/apps/{$app->id}/builder/charts/from-recommendation", [
            'recommendation_id' => $pareto['id'],
            'page_slug' => 'dashboard',
        ])->assertOk();

    $active = app(AppManifestService::class)->getActiveManifest($app->fresh());
    $charts = collect($active['pages'][0]['blocks'])
        ->flatMap(fn ($b) => $b['blocks'] ?? [$b])
        ->where('chart_type', 'pareto');
    expect($charts)->toHaveCount(1);
});

it('cross-source join reads high volume against low performance', function () {
    $names = [
        'fld_vreason000' => 'reason', 'fld_vtotal0000' => 'total tickets',
        'fld_preason000' => 'reason', 'fld_pfcr000000' => 'fcr pct',
    ];
    $byObject = [
        'obj_vol0000000' => ['object' => volumeObject(), 'rows' => volRows(), 'facts' => []],
        'obj_perf000000' => ['object' => perfObject(), 'rows' => perfRows(), 'facts' => []],
    ];

    $findings = (new CrossSourceAnalyzer(new SemanticProfile))->analyze($byObject, $names, [], true);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe('cross')
        ->and($findings[0]['insight']['type'])->toBe('insight')
        ->and($findings[0]['why'])->toContain('62.5')  // top-2 avg FCR
        ->and($findings[0]['why'])->toContain('85')     // rest avg FCR
        ->and($findings[0]['preview']['kind'])->toBe('scatter');
});

it('adds a cross-source finding as an insight block', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    $manifest = [
        'id' => $app->id, 'name' => 'T', 'slug' => 't', 'version' => 1,
        'schema_version' => '1.0.0', 'settings' => ['default_locale' => 'es-MX'],
        'objects' => [volumeObject(), perfObject()],
        'pages' => [[
            'id' => 'pag_x000000000', 'slug' => 'dashboard', 'name' => 'D', 'path' => '/dashboard',
            'blocks' => [['id' => 'blk_h0000000000', 'type' => 'heading', 'level' => 3, 'content' => 'Desglose']],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_x000000000', 'slug' => 'admin', 'name' => 'Admin']]],
        'workflows' => [],
    ];
    app(AppManifestService::class)->createVersion($app, $manifest, $user);

    $reader = Mockery::mock(ConnectedObjectReader::class);
    $reader->shouldReceive('list')->andReturnUsing(
        fn ($object) => ['ok' => true, 'rows' => ($object['id'] === 'obj_vol0000000') ? volRows() : perfRows()],
    );
    app()->instance(ConnectedObjectReader::class, $reader);
    app()->instance(ConnectedIntegrationResolver::class, tap(Mockery::mock(ConnectedIntegrationResolver::class), fn ($m) => $m->shouldReceive('resolve')->andReturn(Mockery::mock(Integration::class))));

    $recs = $this->actingAs($user)
        ->getJson("/apps/{$app->id}/builder/recommendations?page=dashboard")
        ->assertOk()->json('recommendations');

    $cross = collect($recs)->firstWhere('form', 'insight');
    expect($cross)->not->toBeNull();

    $this->actingAs($user)
        ->postJson("/apps/{$app->id}/builder/charts/from-recommendation", [
            'recommendation_id' => $cross['id'], 'page_slug' => 'dashboard',
        ])->assertOk();

    $active = app(AppManifestService::class)->getActiveManifest($app->fresh());
    $insights = collect($active['pages'][0]['blocks'])
        ->flatMap(fn ($b) => $b['blocks'] ?? [$b])
        ->where('type', 'insight');
    expect($insights)->toHaveCount(1)
        ->and($insights->first()['body'])->toContain('%');
});

it('proposes a derived reopen-rate the board does not carry', function () {
    $object = [
        'id' => 'obj_ts00000000', 'name' => 'Tickets Time Series',
        'fields' => [
            ['id' => 'm_total', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
            ['id' => 'm_reop', 'slug' => 'reopened', 'name' => 'Reopened', 'type' => 'number'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'm_total', 'external_path' => 'total'],
            ['field_id' => 'm_reop', 'external_path' => 'reop'],
        ]],
    ];
    $rows = [
        ['total' => 1000, 'reop' => 20],
        ['total' => 1000, 'reop' => 44],
    ]; // 64 / 2000 = 3.2%

    $findings = (new DerivedMetricProposer(new SemanticProfile))->analyze(
        ['o' => ['object' => $object, 'rows' => $rows, 'facts' => []]],
        true,
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe('derived')
        ->and($findings[0]['title'])->toBe('Tasa de reapertura')
        ->and($findings[0]['insight']['body'])->toContain('3.2%')
        ->and($findings[0]['insight']['type'])->toBe('insight');
});

it('run-rate: a declining trend gets an ETA to zero', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    $obj = [
        'id' => 'obj_ts00000000', 'slug' => 'tickets_time_series', 'name' => 'Tickets Time Series',
        'fields' => [
            ['id' => 'f_date', 'slug' => 'bucket_start', 'name' => 'Bucket Start', 'type' => 'date'],
            ['id' => 'f_bl', 'slug' => 'backlog_open', 'name' => 'Backlog Open', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => [
                ['field_id' => 'f_date', 'external_path' => 'bucket_start'],
                ['field_id' => 'f_bl', 'external_path' => 'backlog'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'x', 'collection_path' => 'rows']],
        ],
    ];
    $rows = [
        ['bucket_start' => '2026-05-04', 'backlog' => 100],
        ['bucket_start' => '2026-05-11', 'backlog' => 90],
        ['bucket_start' => '2026-05-18', 'backlog' => 80],
        ['bucket_start' => '2026-05-25', 'backlog' => 70],
        ['bucket_start' => '2026-06-01', 'backlog' => 60],
    ];

    $result = makeRecommender($rows)->recommend(
        $app,
        ['objects' => [$obj], 'settings' => ['default_locale' => 'es-MX']],
        ['id' => 'p', 'slug' => 'dashboard', 'blocks' => []],
        $user,
        'es',
    );

    $trend = collect($result['recommendations'])->firstWhere('form', 'area');
    expect($trend)->not->toBeNull()
        ->and($trend['why'])->toContain('semanas para llegar a 0');

    app(TenantContext::class)->forget();
});

it('reads a NATIVE object and recommends over its records', function () {
    // The analyst used to read connected sources only, so an app whose data
    // lives in its own records — the ordinary case — got zero recommendations.
    // Nothing is mocked here: the rows come from the record store, through the
    // same port a connected source goes through.
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    $manifest = [
        'id' => $app->id, 'name' => 'Soporte', 'slug' => 'soporte', 'version' => 1,
        'schema_version' => '1.0.0', 'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => 'obj_native00000', 'slug' => 'tickets', 'name' => 'Tickets',
            'fields' => [
                ['id' => 'fld_nreason000', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
                ['id' => 'fld_ntotal0000', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_n000000000', 'slug' => 'dashboard', 'name' => 'D', 'path' => '/dashboard',
            'blocks' => [['id' => 'blk_n000000000', 'type' => 'heading', 'level' => 3, 'content' => 'Desglose']],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_n000000000', 'slug' => 'admin', 'name' => 'Admin']]],
        'workflows' => [],
    ];
    app(AppManifestService::class)->createVersion($app, $manifest, $user);

    foreach (concentratedRows() as $row) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => 'obj_native00000',
            'organization_id' => null,
            'data' => ['reason' => $row['reason'], 'total_tickets' => $row['total']],
        ]);
    }

    $body = $this->actingAs($user)
        ->getJson("/apps/{$app->id}/builder/recommendations?page=dashboard")
        ->assertOk()
        ->json();

    expect($body['sources'])->toBe(1)
        ->and($body['total_rows'])->toBe(7);

    $pareto = collect($body['recommendations'])->firstWhere('form', 'pareto');
    expect($pareto)->not->toBeNull()
        // Grounded in the records themselves: Envíos is the top category.
        ->and($pareto['preview']['values'][0])->toEqual(412)
        ->and($body['sources_detail'][0]['measures'])->toContain('Total Tickets')
        ->and($body['sources_detail'][0]['dimensions'])->toContain('Reason');

    // And it inserts: the whole «Agregar» path works over native data too.
    $this->actingAs($user)
        ->postJson("/apps/{$app->id}/builder/charts/from-recommendation", [
            'recommendation_id' => $pareto['id'],
            'page_slug' => 'dashboard',
        ])->assertOk();

    $active = app(AppManifestService::class)->getActiveManifest($app->fresh());
    $charts = collect($active['pages'][0]['blocks'])
        ->flatMap(fn ($b) => $b['blocks'] ?? [$b])
        ->where('chart_type', 'pareto');
    expect($charts)->toHaveCount(1)
        ->and($charts->first()['data_source']['object_id'])->toBe('obj_native00000');
});
