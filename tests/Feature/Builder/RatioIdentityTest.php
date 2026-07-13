<?php

use App\Models\App;
use App\Models\User;
use App\Services\Analyst\AnalystCore;
use App\Services\Analyst\AnomalyFinder;
use App\Services\Analyst\CrossSourceAnalyzer;
use App\Services\Analyst\DataQualityCheck;
use App\Services\Analyst\DerivedMetricProposer;
use App\Services\Analyst\DomainClassifier;
use App\Services\Analyst\FindingBlock;
use App\Services\Analyst\MaturationCheck;
use App\Services\Analyst\RatioIdentity;
use App\Services\Analyst\RecommendationNarrator;
use App\Services\Express\ComputedFactsBuilder;
use App\Services\Express\SemanticProfile;
use App\Services\Records\ObjectRowSource;
use App\Support\Tenancy\TenantContext;

function ratioCore(array $rows): AnalystCore
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
        new RatioIdentity(new SemanticProfile),
        new MaturationCheck,
    );
}

/**
 * A rate is not the average of its rates.
 *
 * `avg(otd_pct)` weights a day with 3 orders exactly like a day with 500, so one
 * late order on a quiet day reads as a 0% catastrophe and the headline number is
 * simply not the rate. The honest figure is volume-weighted: SUM(numerator) ÷
 * SUM(denominator).
 *
 * The identity is DISCOVERED from the arithmetic, never guessed from the names —
 * a column called `conversion_pct` could be anything, and the real board's OTD
 * turned out to be (delivered − late) ÷ total, which no naming convention would
 * have revealed.
 */
function ratioObject(array $fields, array $map): array
{
    return [
        'id' => 'obj_ratio0000', 'slug' => 'otd', 'name' => 'Otd',
        'fields' => $fields,
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => $map,
            'operations' => ['list' => ['mcp_tool' => 'x', 'collection_path' => 'rows']],
        ],
    ];
}

/** delivered / total — a rate a KPI can express. */
function simpleRatioObject(): array
{
    return ratioObject([
        ['id' => 'f_total', 'slug' => 'total_pedidos', 'name' => 'Total Pedidos', 'type' => 'number'],
        ['id' => 'f_ok', 'slug' => 'pedidos_entregados', 'name' => 'Pedidos Entregados', 'type' => 'number'],
        ['id' => 'f_rate', 'slug' => 'otd_pct', 'name' => 'Otd Pct', 'type' => 'number'],
    ], [
        ['field_id' => 'f_total', 'external_path' => 'total'],
        ['field_id' => 'f_ok', 'external_path' => 'ok'],
        ['field_id' => 'f_rate', 'external_path' => 'rate'],
    ]);
}

/**
 * The real shape: a source reports delivered and late, never on-time. Its OTD is
 * (delivered − late) ÷ total.
 */
function differenceRatioObject(): array
{
    return ratioObject([
        ['id' => 'f_total', 'slug' => 'total_pedidos', 'name' => 'Total Pedidos', 'type' => 'number'],
        ['id' => 'f_ok', 'slug' => 'pedidos_entregados', 'name' => 'Pedidos Entregados', 'type' => 'number'],
        ['id' => 'f_late', 'slug' => 'pedidos_retrasados', 'name' => 'Pedidos Retrasados', 'type' => 'number'],
        ['id' => 'f_rate', 'slug' => 'otd_pct', 'name' => 'Otd Pct', 'type' => 'number'],
    ], [
        ['field_id' => 'f_total', 'external_path' => 'total'],
        ['field_id' => 'f_ok', 'external_path' => 'ok'],
        ['field_id' => 'f_late', 'external_path' => 'late'],
        ['field_id' => 'f_rate', 'external_path' => 'rate'],
    ]);
}

it('proves a simple rate from the arithmetic, and computes it as a rate', function () {
    // A quiet day (2 of 3) and busy days (90 of 100). The unweighted mean of the
    // daily rates is dragged down by the quiet day; the true rate is not.
    $rows = [];
    foreach ([[3, 2], [3, 1], [100, 90], [100, 92], [100, 88], [100, 91], [100, 89], [100, 90]] as [$total, $ok]) {
        $rows[] = ['total' => $total, 'ok' => $ok, 'rate' => round($ok / $total * 100, 2)];
    }

    $found = (new RatioIdentity(new SemanticProfile))->detect(simpleRatioObject(), $rows);

    expect($found)->toHaveCount(1);
    $id = $found[0];

    expect($id['numerator']['id'])->toBe('f_ok')
        ->and($id['denominator']['id'])->toBe('f_total')
        ->and($id['minus'])->toBeNull()
        // Proven on every row, not inferred from a column name.
        ->and($id['matched'])->toBe(8)
        ->and($id['rows'])->toBe(8)
        // 543 delivered of 606 ordered.
        ->and($id['true_rate'])->toBe(89.6)
        // What averaging the column says instead: the two 3-order days (67% and
        // 33%) count as much as the 100-order ones, dragging the headline down
        // almost ten points below the rate the business actually achieved.
        ->and($id['averaged_rate'])->toBe(80.0)
        ->and($id['expressible_as_kpi'])->toBeTrue();
});

it('proposes the ratio KPI, and never gauges the rate it would miscompute', function () {
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    $rows = [];
    foreach ([[3, 2], [3, 1], [100, 90], [100, 92], [100, 88], [100, 91], [100, 89], [100, 90]] as [$total, $ok]) {
        $rows[] = ['total' => $total, 'ok' => $ok, 'rate' => round($ok / $total * 100, 2)];
    }

    $result = ratioCore($rows)->analyze(
        $app,
        ['objects' => [simpleRatioObject()], 'settings' => ['default_locale' => 'es-MX']],
        $user, 'es', [], 20,
    );

    $kpi = collect($result['findings'])->firstWhere('kind', 'rate_kpi');
    expect($kpi)->not->toBeNull()
        ->and($kpi['why'])->toContain('89.6')        // the true rate
        ->and($kpi['why'])->toContain('80')          // and the one averaging shows
        ->and($kpi['kpi']['field_id'])->toBe('f_ok')  // numerator
        ->and($kpi['kpi']['aggregation'])->toBe('sum')
        ->and($kpi['kpi']['ratio_denominator']['field_id'])->toBe('f_total')
        ->and($kpi['kpi']['ratio_denominator']['aggregation'])->toBe('sum')
        // It renders as a stat, which is the only block that can express a ratio.
        ->and(FindingBlock::forFinding($kpi)['block']['type'])->toBe('stat');

    // A gauge renders avg(field). For this rate that is the WRONG number, and a
    // dial is the most confident way to be wrong — so it is not offered.
    expect(collect($result['findings'])->firstWhere('kind', 'gauge'))->toBeNull();

    app(TenantContext::class)->forget();
});

it('says so when no KPI can state the rate honestly', function () {
    // The real board: on-time is delivered MINUS late, and no column carries it.
    // ratio_denominator can only point at a single column, so the platform cannot
    // compute this rate — and a wrong number in a dial is worse than a sentence.
    config(['cache.default' => 'array']);
    $user = User::factory()->create();
    app(TenantContext::class)->set(null, $user->id);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);

    $rows = [];
    foreach ([[7, 6, 3], [8, 8, 6], [6, 6, 1], [100, 95, 5], [100, 96, 4], [100, 94, 6], [100, 97, 3], [100, 95, 5]] as [$total, $ok, $late]) {
        $rows[] = ['total' => $total, 'ok' => $ok, 'late' => $late, 'rate' => round(($ok - $late) / $total * 100, 2)];
    }

    $result = ratioCore($rows)->analyze(
        $app,
        ['objects' => [differenceRatioObject()], 'settings' => ['default_locale' => 'es-MX']],
        $user, 'es', [], 20,
    );

    $warning = collect($result['data_quality'])->first(fn ($q) => str_contains($q['text'], 'Otd Pct'));
    expect($warning)->not->toBeNull()
        ->and($warning['level'])->toBe('warn')
        // It names the formula the data proved, and both numbers.
        ->and($warning['text'])->toContain('pedidos entregados − pedidos retrasados')
        ->and($warning['text'])->toContain('ningún KPI puede calcularla bien');

    // No KPI is proposed (none could be right), and no gauge either.
    expect(collect($result['findings'])->pluck('kind'))->not->toContain('rate_kpi')
        ->and(collect($result['findings'])->firstWhere('kind', 'gauge'))->toBeNull();

    app(TenantContext::class)->forget();
});

it('claims no identity the data does not carry', function () {
    // Three unrelated columns. A detector that reasoned from names would find
    // "otd_pct" next to "total_pedidos" and invent a relationship; the arithmetic
    // refuses to.
    $rows = [];
    foreach ([[10, 3, 41.2], [20, 7, 88.9], [30, 2, 15.5], [40, 9, 62.3], [50, 4, 77.1], [60, 8, 33.8], [70, 1, 95.0], [80, 6, 50.4]] as [$total, $ok, $rate]) {
        $rows[] = ['total' => $total, 'ok' => $ok, 'rate' => $rate];
    }

    expect((new RatioIdentity(new SemanticProfile))->detect(simpleRatioObject(), $rows))->toBeEmpty();
});
