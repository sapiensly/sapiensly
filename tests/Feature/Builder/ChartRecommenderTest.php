<?php

use App\Models\App;
use App\Models\Integration;
use App\Models\Record;
use App\Models\User;
use App\Services\Analyst\AnalystCore;
use App\Services\Analyst\AnomalyFinder;
use App\Services\Analyst\CrossSourceAnalyzer;
use App\Services\Analyst\DataQualityCheck;
use App\Services\Analyst\DerivedMetricProposer;
use App\Services\Analyst\DomainClassifier;
use App\Services\Analyst\FindingBlock;
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
        new AnomalyFinder,
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
        // The narrative still carries today's number…
        ->and($findings[0]['why'])->toContain('3.2%');

    // …but a ratio is a METRIC, so it goes up as a LIVE KPI, not as a sentence
    // quoting a number that is stale the moment a ticket is reopened.
    $kpi = $findings[0]['kpi'];
    expect($kpi['aggregation'])->toBe('sum')
        ->and($kpi['field_id'])->toBe('m_reop')
        ->and($kpi['ratio_denominator']['field_id'])->toBe('m_total')
        ->and($kpi['ratio_denominator']['aggregation'])->toBe('sum')
        ->and($kpi['format'])->toBe('percentage')
        // …and it renders as a stat block, not an insight.
        ->and(FindingBlock::forFinding($findings[0])['block']['type'])->toBe('stat');
});

it('names the day something happened — the outlier the trend line never says', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    // A steady backlog with one violent spike. The trend chart draws the peak;
    // nothing on the board ever SAYS it — and the outlier has been computed all
    // along, and thrown away.
    $obj = [
        'id' => 'obj_anom0000', 'slug' => 'backlog_daily', 'name' => 'Backlog Daily',
        'fields' => [
            ['id' => 'f_d', 'slug' => 'day', 'name' => 'Day', 'type' => 'date'],
            ['id' => 'f_b', 'slug' => 'backlog_open', 'name' => 'Backlog Open', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => [
                ['field_id' => 'f_d', 'external_path' => 'day'],
                ['field_id' => 'f_b', 'external_path' => 'backlog'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'x', 'collection_path' => 'rows']],
        ],
    ];
    $rows = [
        ['day' => '2026-05-04', 'backlog' => 100], ['day' => '2026-05-05', 'backlog' => 104],
        ['day' => '2026-05-06', 'backlog' => 98], ['day' => '2026-05-07', 'backlog' => 102],
        ['day' => '2026-05-08', 'backlog' => 101], ['day' => '2026-05-09', 'backlog' => 99],
        ['day' => '2026-05-10', 'backlog' => 103], ['day' => '2026-05-11', 'backlog' => 412],
    ];

    $result = makeCore($rows)->analyze(
        $app,
        ['objects' => [$obj], 'settings' => ['default_locale' => 'es-MX']],
        $user,
        'es',
    );

    $anomaly = collect($result['findings'])->firstWhere('kind', 'anomaly');
    expect($anomaly)->not->toBeNull()
        ->and($anomaly['why'])->toContain('2026-05-11')  // the day itself
        ->and($anomaly['why'])->toContain('412')          // what it hit
        ->and($anomaly['why'])->toContain('σ')            // how far out that is
        // Loud enough to be a risk to look at, not a conclusion to file away.
        ->and($anomaly['insight']['variant'])->toBe('risk')
        ->and($anomaly['flag']['tone'])->toBe('hot');

    app(TenantContext::class)->forget();
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

it('volume vs rate: a dual-axis combo, and it replaces the plain Pareto', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    // One source that carries BOTH how much (tickets) and how well (FCR) per
    // reason. A Pareto of the volume alone can't say where improving pays.
    $obj = [
        'id' => 'obj_combo0000', 'slug' => 'tickets_by_reason', 'name' => 'Tickets By Reason',
        'fields' => [
            ['id' => 'f_reason', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'f_total', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
            ['id' => 'f_fcr', 'slug' => 'fcr_pct', 'name' => 'Fcr Pct', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => [
                ['field_id' => 'f_reason', 'external_path' => 'reason'],
                ['field_id' => 'f_total', 'external_path' => 'total'],
                ['field_id' => 'f_fcr', 'external_path' => 'fcr'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'x', 'collection_path' => 'rows']],
        ],
    ];
    $rows = [
        ['reason' => 'Envíos', 'total' => 412, 'fcr' => 61],
        ['reason' => 'Cobranza', 'total' => 286, 'fcr' => 64],
        ['reason' => 'Garantías', 'total' => 96, 'fcr' => 88],
        ['reason' => 'Precompra', 'total' => 74, 'fcr' => 90],
        ['reason' => 'Créditos', 'total' => 52, 'fcr' => 85],
        ['reason' => 'Devoluciones', 'total' => 29, 'fcr' => 92],
        ['reason' => 'Otros', 'total' => 15, 'fcr' => 80],
    ];

    $result = makeCore($rows)->analyze(
        $app,
        ['objects' => [$obj], 'settings' => ['default_locale' => 'es-MX']],
        $user,
        'es',
    );

    $combo = collect($result['findings'])->firstWhere('kind', 'combo');
    expect($combo)->not->toBeNull()
        ->and($combo['chart']['series'])->toHaveCount(2)
        // Volume on the left as bars, the rate on its OWN axis as a line — that
        // second axis is the entire point: the two are read against each other.
        ->and($combo['chart']['series'][0]['type'])->toBe('bar')
        ->and($combo['chart']['series'][0]['field_id'])->toBe('f_total')
        ->and($combo['chart']['series'][0]['axis'])->toBe('left')
        ->and($combo['chart']['series'][1]['type'])->toBe('line')
        ->and($combo['chart']['series'][1]['field_id'])->toBe('f_fcr')
        ->and($combo['chart']['series'][1]['aggregation'])->toBe('avg')
        ->and($combo['chart']['series'][1]['axis'])->toBe('right')
        // The preview draws both series, each on its own scale.
        ->and($combo['preview']['kind'])->toBe('combo')
        ->and($combo['preview']['values'][0])->toBe(412.0)
        ->and($combo['preview']['line'][0])->toBe(61.0);

    // It is the SAME cut as the Pareto, told better — so the board is never
    // offered both. The combo wins on score; the Pareto never surfaces.
    expect(collect($result['findings'])->firstWhere('kind', 'pareto'))->toBeNull()
        ->and($combo['semantic_key'])->toBe('breakdown|total tickets|reason');

    app(TenantContext::class)->forget();
});

it('finds the correlation no single-measure chart can show, and draws it as a scatter', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    // Two measures that move together: the longer the first response, the lower
    // the satisfaction. No chart on the board can say this — it lives in the
    // relationship between the columns, not in either one.
    $obj = [
        'id' => 'obj_corr000000', 'slug' => 'tickets_resolved', 'name' => 'Tickets Resolved',
        'fields' => [
            ['id' => 'f_frt', 'slug' => 'first_response_hours', 'name' => 'First Response Hours', 'type' => 'number'],
            ['id' => 'f_csat', 'slug' => 'csat_score', 'name' => 'Csat Score', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => [
                ['field_id' => 'f_frt', 'external_path' => 'frt'],
                ['field_id' => 'f_csat', 'external_path' => 'csat'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'x', 'collection_path' => 'rows']],
        ],
    ];
    $rows = [
        ['frt' => 1, 'csat' => 96], ['frt' => 2, 'csat' => 92], ['frt' => 3, 'csat' => 90],
        ['frt' => 5, 'csat' => 84], ['frt' => 6, 'csat' => 81], ['frt' => 8, 'csat' => 74],
        ['frt' => 10, 'csat' => 70], ['frt' => 12, 'csat' => 63], ['frt' => 14, 'csat' => 58],
        ['frt' => 16, 'csat' => 55],
    ];

    $result = makeCore($rows)->analyze(
        $app,
        ['objects' => [$obj], 'settings' => ['default_locale' => 'es-MX']],
        $user,
        'es',
    );

    $corr = collect($result['findings'])->firstWhere('kind', 'correlation');
    expect($corr)->not->toBeNull()
        // Inverse relationship, stated with the real coefficient.
        ->and($corr['why'])->toContain('sentido contrario')
        ->and($corr['why'])->toContain('10 registros')
        ->and($corr['chart']['chart_type'])->toBe('scatter')
        ->and($corr['chart']['x_field_id'])->toBe('f_frt')
        ->and($corr['chart']['y_field_id'])->toBe('f_csat')
        // A near-perfect relationship is flagged.
        ->and($corr['flag']['tone'])->toBe('hot')
        // The preview draws the same points the real chart will.
        ->and($corr['preview']['kind'])->toBe('scatter')
        ->and($corr['preview']['points'][0])->toBe([1.0, 96.0])
        // x vs y and y vs x are the SAME finding — the pair is sorted.
        ->and($corr['semantic_key'])->toBe('correlation|csat score|first response hours');

    app(TenantContext::class)->forget();
});

it('never claims a correlation with an identifier, nor a weak one', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    // ticket_id rises perfectly with insertion order — r = 1.0 against anything
    // monotonic, and a finding a real analyst would never make. Meanwhile the
    // two REAL measures barely relate.
    $obj = [
        'id' => 'obj_noise0000', 'slug' => 'noise', 'name' => 'Noise',
        'fields' => [
            ['id' => 'f_id', 'slug' => 'ticket_id', 'name' => 'Ticket Id', 'type' => 'number'],
            ['id' => 'f_a', 'slug' => 'reply_count', 'name' => 'Reply Count', 'type' => 'number'],
            ['id' => 'f_b', 'slug' => 'attachments_count', 'name' => 'Attachments Count', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => [
                ['field_id' => 'f_id', 'external_path' => 'ticket_id'],
                ['field_id' => 'f_a', 'external_path' => 'a'],
                ['field_id' => 'f_b', 'external_path' => 'b'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'x', 'collection_path' => 'rows']],
        ],
    ];
    $rows = [
        ['ticket_id' => 1, 'a' => 3, 'b' => 1], ['ticket_id' => 2, 'a' => 7, 'b' => 0],
        ['ticket_id' => 3, 'a' => 2, 'b' => 2], ['ticket_id' => 4, 'a' => 9, 'b' => 1],
        ['ticket_id' => 5, 'a' => 4, 'b' => 3], ['ticket_id' => 6, 'a' => 6, 'b' => 0],
        ['ticket_id' => 7, 'a' => 1, 'b' => 2], ['ticket_id' => 8, 'a' => 8, 'b' => 1],
    ];

    $result = makeCore($rows)->analyze(
        $app,
        ['objects' => [$obj], 'settings' => ['default_locale' => 'es-MX']],
        $user,
        'es',
    );

    expect(collect($result['findings'])->firstWhere('kind', 'correlation'))->toBeNull();

    app(TenantContext::class)->forget();
});

/** Record-level tickets: reason → owner, hours, a date. The row-level shape. */
function ticketRows(int $n = 40): array
{
    $reasons = ['Envíos', 'Cobranza', 'Garantías'];
    $owners = ['Ana', 'Beto'];
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = [
            'reason' => $reasons[$i % 3],
            'owner' => $owners[$i % 2],
            // A long tail: most tickets are quick, a few are disasters.
            'hours' => $i % 10 === 0 ? 40 + $i : 2 + ($i % 5),
            'day' => date('Y-m-d', strtotime('2025-01-01 +'.($i * 16).' days')),
        ];
    }

    return $rows;
}

function ticketObject(): array
{
    return [
        'id' => 'obj_tick00000', 'slug' => 'tickets', 'name' => 'Tickets',
        'fields' => [
            ['id' => 'f_reason', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'f_owner', 'slug' => 'owner', 'name' => 'Owner', 'type' => 'string'],
            ['id' => 'f_hours', 'slug' => 'total_hours', 'name' => 'Total Hours', 'type' => 'number'],
            ['id' => 'f_day', 'slug' => 'day', 'name' => 'Day', 'type' => 'date'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => [
                ['field_id' => 'f_reason', 'external_path' => 'reason'],
                ['field_id' => 'f_owner', 'external_path' => 'owner'],
                ['field_id' => 'f_hours', 'external_path' => 'hours'],
                ['field_id' => 'f_day', 'external_path' => 'day'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'x', 'collection_path' => 'rows']],
        ],
    ];
}

it('completes the arsenal: flow, composition, distribution and seasonality', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    // 40 tickets spanning ~21 months: two categoricals that co-occur, a measure
    // with a long tail, and enough history to have a season.
    $result = makeCore(ticketRows())->analyze(
        $app,
        ['objects' => [ticketObject()], 'settings' => ['default_locale' => 'es-MX']],
        $user,
        'es',
        [],
        20, // ask for the whole arsenal, not just the top 5
    );

    $byKind = collect($result['findings'])->keyBy('kind');

    // FLOW — where the work goes. Directional: reason → owner, never the reverse.
    $flow = $byKind['flow'];
    expect($flow['chart']['chart_type'])->toBe('sankey')
        ->and($flow['chart']['group_by_field_id'])->toBe('f_reason')
        ->and($flow['chart']['series_field_id'])->toBe('f_owner')
        ->and($flow['semantic_key'])->toBe('flow|reason|owner');

    // COMPOSITION — the trend says the total moves; this says which part moves it.
    $composition = $byKind['composition'];
    expect($composition['chart']['stacked'])->toBeTrue()
        ->and($composition['chart']['series_field_id'])->toBe('f_reason')
        ->and($composition['chart']['bucket'])->toBe('month')
        ->and($composition['semantic_key'])->toBe('composition|total hours|reason');

    // DISTRIBUTION — the average is a liar, and the card says so with real quartiles.
    $distribution = $byKind['distribution'];
    expect($distribution['chart']['chart_type'])->toBe('box')
        ->and($distribution['why'])->toContain('mediana')
        ->and($distribution['why'])->toContain('1 de cada 4')
        ->and($distribution['flag']['tone'])->toBe('hot')   // the tail is long
        ->and($distribution['semantic_key'])->toBe('distribution|total hours|reason');

    // SEASONALITY — earned only by having enough history to HAVE a season.
    $seasonality = $byKind['seasonality'];
    expect($seasonality['chart']['bucket'])->toBe('quarter')
        ->and($seasonality['semantic_key'])->toBe('seasonality|total hours|quarter');

    // The forms all read the same object, and several share its measure and its
    // dimension — so the thing that keeps them from cannibalising each other is
    // that each asks a DIFFERENT question. No two findings may collapse onto one
    // key, or the board silently loses one of them.
    $keys = collect($result['findings'])->pluck('semantic_key');
    expect($keys->duplicates())->toBeEmpty()
        ->and($keys->count())->toBeGreaterThanOrEqual(5);

    app(TenantContext::class)->forget();
});

it('refuses a season it has not lived, and a spread it cannot measure', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    // Six tickets over six days: no year to find a season in, and one record per
    // category — quartiles of a single value are theatre.
    $rows = collect(range(0, 5))->map(fn (int $i) => [
        'reason' => 'R'.$i,
        'owner' => 'A',
        'hours' => 3,
        'day' => date('Y-m-d', strtotime('2026-06-01 +'.$i.' days')),
    ])->all();

    $result = makeCore($rows)->analyze(
        $app,
        ['objects' => [ticketObject()], 'settings' => ['default_locale' => 'es-MX']],
        $user,
        'es',
        [],
        20,
    );

    $kinds = collect($result['findings'])->pluck('kind');
    expect($kinds)->not->toContain('seasonality')  // 6 days is not a year
        ->and($kinds)->not->toContain('distribution'); // one row per category has no spread

    app(TenantContext::class)->forget();
});

/** A ratio measure over N periods, optionally alongside a declared target column. */
function fcrObject(bool $withTarget = false): array
{
    $fields = [
        ['id' => 'f_week', 'slug' => 'week', 'name' => 'Week', 'type' => 'date'],
        ['id' => 'f_fcr', 'slug' => 'fcr_pct', 'name' => 'Fcr Pct', 'type' => 'number'],
    ];
    $map = [
        ['field_id' => 'f_week', 'external_path' => 'week'],
        ['field_id' => 'f_fcr', 'external_path' => 'fcr'],
    ];
    if ($withTarget) {
        $fields[] = ['id' => 'f_meta', 'slug' => 'meta_fcr', 'name' => 'Meta Fcr', 'type' => 'number'];
        $map[] = ['field_id' => 'f_meta', 'external_path' => 'meta'];
    }

    return [
        'id' => 'obj_fcr00000', 'slug' => 'fcr_weekly', 'name' => 'Fcr Weekly',
        'fields' => $fields,
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => $map,
            'operations' => ['list' => ['mcp_tool' => 'x', 'collection_path' => 'rows']],
        ],
    ];
}

it('never invents a target — it uses the one the source declares', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    // The source carries the business's OWN goal, right next to the measure.
    $rows = collect(range(0, 7))->map(fn (int $i) => [
        'week' => date('Y-m-d', strtotime('2026-04-01 +'.($i * 7).' days')),
        'fcr' => 70 + ($i % 3),
        'meta' => 85,
    ])->all();

    $result = makeCore($rows)->analyze(
        $app,
        ['objects' => [fcrObject(true)], 'settings' => ['default_locale' => 'es-MX']],
        $user, 'es', [], 20,
    );

    $gauge = collect($result['findings'])->firstWhere('kind', 'gauge');
    expect($gauge)->not->toBeNull()
        ->and($gauge['chart']['max_value'])->toBe(85.0)         // the declared goal, not 80
        ->and($gauge['why'])->toContain('la meta que trae la fuente')
        ->and($gauge['flag']['tone'])->toBe('gap');

    app(TenantContext::class)->forget();
});

it('with no declared target, it benchmarks against the best the org actually hit', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    // No goal column — but the org HAS done better: it reached ~90 in its best
    // weeks. That is a fact, not a wish, and it is reachable by definition.
    $values = [70, 72, 68, 75, 71, 69, 90, 88];
    $rows = collect($values)->map(fn (int $v, int $i) => [
        'week' => date('Y-m-d', strtotime('2026-04-01 +'.($i * 7).' days')),
        'fcr' => $v,
    ])->all();

    $result = makeCore($rows)->analyze(
        $app,
        ['objects' => [fcrObject()], 'settings' => ['default_locale' => 'es-MX']],
        $user, 'es', [], 20,
    );

    $gauge = collect($result['findings'])->firstWhere('kind', 'gauge');
    expect($gauge)->not->toBeNull()
        ->and($gauge['why'])->toContain('tu mejor periodo')
        ->and($gauge['why'])->toContain('Ya lo lograste una vez')
        // The benchmark is the 90th percentile of what it actually achieved…
        ->and($gauge['chart']['max_value'])->toBeGreaterThan(80.0)
        // …and never the flat 80 we used to make up.
        ->and($gauge['chart']['max_value'])->not->toBe(80);

    app(TenantContext::class)->forget();
});

it('claims no goal it cannot defend', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    // A flat measure with no target column and no better past: there is nothing
    // to close, and the old code would still have announced "3.2 pts from the
    // 80% target" — a goal nobody set.
    $rows = collect(range(0, 7))->map(fn (int $i) => [
        'week' => date('Y-m-d', strtotime('2026-04-01 +'.($i * 7).' days')),
        'fcr' => 71,
    ])->all();

    $result = makeCore($rows)->analyze(
        $app,
        ['objects' => [fcrObject()], 'settings' => ['default_locale' => 'es-MX']],
        $user, 'es', [], 20,
    );

    $gauge = collect($result['findings'])->firstWhere('kind', 'gauge');
    expect($gauge)->not->toBeNull()
        ->and($gauge['why'])->toContain('No hay meta declarada')
        ->and($gauge['why'])->not->toContain('80%')
        ->and($gauge['flag'])->toBeNull()          // nothing to be behind on
        ->and($gauge['chart']['max_value'])->toBe(100); // the scale's ceiling, not a goal

    app(TenantContext::class)->forget();
});

it('never sums a percentage, however the column is named', function () {
    // A rate whose SLUG says nothing (col_7) — typed off the slug alone it was
    // ADDITIVE, and additive means summable. A percentage that gets summed is a
    // number that cannot exist.
    $semantics = new SemanticProfile;
    $object = [
        'id' => 'o', 'name' => 'X',
        'fields' => [['id' => 'f', 'slug' => 'col_7', 'name' => 'Tasa de reapertura', 'type' => 'number']],
        'source' => ['field_map' => [['field_id' => 'f', 'external_path' => 'v']]],
    ];
    $rows = [['v' => 3.2], ['v' => 4.1], ['v' => 2.8]];
    $field = $object['fields'][0];

    expect($semantics->measureTypeIn($object, $rows, $field))->toBe(SemanticProfile::MEASURE_RATIO)
        // …and the legality matrix then refuses to sum it.
        ->and($semantics->legalKpiAggregations(SemanticProfile::MEASURE_RATIO, SemanticProfile::GRAIN_RAW))
        ->not->toContain('sum');
});

it('ranks by the data when the domain is one it has never seen', function () {
    // An unrecognised business has NO headline terms, so the relevance bonus was
    // always zero and every analysis looked equally relevant. What a source is
    // named after is what it is about.
    $core = makeCore([]);
    $method = new ReflectionMethod($core, 'isCentralTo');

    expect($method->invoke($core, 'Vessel Berth Turnaround', 'Turnaround Hours'))->toBeTrue()
        ->and($method->invoke($core, 'Vessel Berth Turnaround', 'Berth'))->toBeTrue()
        // An incidental column is not what the source is for.
        ->and($method->invoke($core, 'Vessel Berth Turnaround', 'Updated At Ms'))->toBeFalse()
        // Stop-words would make everything central, which is the same as nothing.
        ->and($method->invoke($core, 'Tickets por motivo', 'Peso por unidad'))->toBeFalse();
});

/** Orders carrying the customer's signup date — the shape a cohort lives in. */
function cohortObject(): array
{
    return [
        'id' => 'obj_coh00000', 'slug' => 'orders', 'name' => 'Orders',
        'fields' => [
            ['id' => 'f_cust', 'slug' => 'customer_id', 'name' => 'Customer Id', 'type' => 'string'],
            ['id' => 'f_signup', 'slug' => 'signed_up_at', 'name' => 'Signed Up At', 'type' => 'date'],
            ['id' => 'f_order', 'slug' => 'ordered_at', 'name' => 'Ordered At', 'type' => 'date'],
            ['id' => 'f_amount', 'slug' => 'amount', 'name' => 'Amount', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => [
                ['field_id' => 'f_cust', 'external_path' => 'cust'],
                ['field_id' => 'f_signup', 'external_path' => 'signup'],
                ['field_id' => 'f_order', 'external_path' => 'ordered'],
                ['field_id' => 'f_amount', 'external_path' => 'amount'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'x', 'collection_path' => 'rows']],
        ],
    ];
}

/**
 * @param  bool  $crossed  half the rows run one way and half the other, so NEITHER
 *                         date consistently follows the other — two dates that cross
 *                         are not an intake and a return, whichever way you read them.
 */
function cohortRows(bool $crossed = false): array
{
    $rows = [];
    $intakes = ['2026-01-05', '2026-02-08', '2026-03-11'];
    for ($i = 0; $i < 30; $i++) {
        $signup = $intakes[$i % 3];
        $ordered = date('Y-m-d', strtotime($signup.' +'.(($i % 5) * 21).' days'));
        $flip = $crossed && $i % 2 === 0;
        $rows[] = [
            'cust' => 'c'.$i,
            'signup' => $flip ? $ordered : $signup,
            'ordered' => $flip ? $signup : $ordered,
            'amount' => 50 + $i,
        ];
    }

    return $rows;
}

it('proposes the cohort table no other card on the board can be', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    $result = makeCore(cohortRows())->analyze(
        $app,
        ['objects' => [cohortObject()], 'settings' => ['default_locale' => 'es-MX']],
        $user, 'es', [], 20,
    );

    $cohort = collect($result['findings'])->firstWhere('kind', 'cohort');
    expect($cohort)->not->toBeNull()
        // A pivot, not a chart: rows are the intake, columns the months since.
        ->and($cohort['chart']['__pivot'])->toBeTrue()
        ->and($cohort['chart']['mode'])->toBe('cohort')
        ->and($cohort['chart']['group_by_field_id'])->toBe('f_signup')
        ->and($cohort['chart']['column_field_id'])->toBe('f_order')
        ->and($cohort['chart']['bucket'])->toBe('month')
        // Without the column bucket every order becomes its own column.
        ->and($cohort['chart']['column_bucket'])->toBe('month')
        // Retention counts the customers who came BACK — one customer ordering
        // twice is one customer, not two.
        ->and($cohort['chart']['aggregation'])->toBe('distinct_count')
        ->and($cohort['chart']['y_field_id'])->toBe('f_cust')
        ->and($cohort['why'])->toContain('3 camadas');

    // It renders as a `pivot` block the board can actually hold.
    $block = FindingBlock::forFinding($cohort)['block'];
    expect($block['type'])->toBe('pivot')
        ->and($block['mode'])->toBe('cohort')
        ->and($block['data_source']['object_id'])->toBe('obj_coh00000')
        ->and($block)->not->toHaveKey('__pivot');

    app(TenantContext::class)->forget();
});

it('refuses a cohort when the two dates are not an intake and a return', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    // The same two dates, crossed: the "activity" happens BEFORE the "start". Two
    // dates that cross each other are not a birth and a return — they are just two
    // dates, and a retention table built on them would be a confident answer to a
    // question nobody asked.
    $result = makeCore(cohortRows(crossed: true))->analyze(
        $app,
        ['objects' => [cohortObject()], 'settings' => ['default_locale' => 'es-MX']],
        $user, 'es', [], 20,
    );

    expect(collect($result['findings'])->pluck('kind'))->not->toContain('cohort');

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
        ->and($charts->first()['data_source']['object_id'])->toBe('obj_native00000')
        // A chart aggregates client-side over the rows it fetches. A connected
        // breakdown source returns one row per CATEGORY, so a dozen is the whole
        // story; an internal object returns one row per RECORD, so a dozen would
        // chart twelve tickets out of hundreds. The window must match the source
        // or the picture is simply false.
        ->and($charts->first()['data_source']['limit'])->toBe(500);
});
