<?php

use App\Models\App;
use App\Models\Integration;
use App\Models\User;
use App\Services\Builder\ChartRecommender;
use App\Services\Builder\DataQualityCheck;
use App\Services\Builder\DomainClassifier;
use App\Services\Builder\RecommendationNarrator;
use App\Services\Connected\ConnectedIntegrationResolver;
use App\Services\Connected\ConnectedObjectReader;
use App\Services\Express\ComputedFactsBuilder;
use App\Services\Express\SemanticProfile;
use App\Services\Manifest\AppManifestService;
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

function makeRecommender(array $rows): ChartRecommender
{
    $reader = Mockery::mock(ConnectedObjectReader::class);
    $reader->shouldReceive('list')->andReturn(['ok' => true, 'rows' => $rows]);

    $integrations = Mockery::mock(ConnectedIntegrationResolver::class);
    $integrations->shouldReceive('resolve')->andReturn(Mockery::mock(Integration::class));

    return new ChartRecommender(
        $reader,
        $integrations,
        new ComputedFactsBuilder,
        new SemanticProfile,
        new DomainClassifier,
        app(RecommendationNarrator::class),
        new DataQualityCheck,
    );
}

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
