<?php

use App\Services\Manifest\DashboardSpecSuggester;

/**
 * The deterministic dashboard suggester (the Express path's author). These
 * pin two prod-observed polish fixes: humane KPI labels (no "Suma …"/"Promedio
 * …" prefix — the aggregation is named by the card subtitle instead) and never
 * charting a recency-capped source (mode:latest) as a count-over-time trend.
 */
function dss_comments_object(?string $mode): array
{
    return [
        'id' => 'obj_npscomments0',
        'slug' => 'nps_comments',
        'name' => 'Nps Comments',
        'fields' => [
            ['id' => 'fld_respondedat', 'slug' => 'responded_at', 'name' => 'Responded', 'type' => 'datetime'],
            ['id' => 'fld_npsvalue000', 'slug' => 'nps', 'name' => 'Nps', 'type' => 'number'],
            ['id' => 'fld_segment0000', 'slug' => 'segment', 'name' => 'Segment', 'type' => 'string'],
        ],
        'source' => [
            'type' => 'connected',
            'operations' => ['list' => array_filter([
                'mcp_tool' => 'get-nps-comments-tool',
                'arguments' => $mode !== null ? ['mode' => $mode] : null,
                'collection_path' => 'comments',
            ], fn ($v) => $v !== null)],
        ],
    ];
}

/** Four distinct days so the trend's bucket count clears the >= 3 threshold. */
function dss_comments_rows(): array
{
    return collect(range(0, 7))->map(fn (int $i) => [
        'responded_at' => now()->utc()->subDays($i)->toIso8601String(),
        'nps' => 6 + ($i % 5),
        'segment' => $i % 2 === 0 ? 'promoter' : 'detractor',
    ])->all();
}

it('features a measure the prompt NAMED that lives in a field, not a tool (nps on a ticket list)', function () {
    // Prod app_…thpsg: "dashboard de NPS de yuhu" acquired a ticket list that
    // CARRIES nps_score as a field, but the board headlined ticket volume and
    // never surfaced nps_score. The prompt topic now leads the band + trend.
    $object = [
        'id' => 'obj_tickets00', 'slug' => 'search_tickets', 'name' => 'Search Tickets',
        'fields' => [
            ['id' => 'fld_created0', 'slug' => 'created_at', 'name' => 'Created', 'type' => 'datetime'],
            ['id' => 'fld_status00', 'slug' => 'status', 'name' => 'Status', 'type' => 'string'],
            ['id' => 'fld_npsscore', 'slug' => 'nps_score', 'name' => 'Nps Score', 'type' => 'number'],
            ['id' => 'fld_totaltix', 'slug' => 'total_tickets', 'name' => 'Total Tickets', 'type' => 'number'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'fld_created0', 'external_path' => 'created_at'],
            ['field_id' => 'fld_status00', 'external_path' => 'status'],
            ['field_id' => 'fld_npsscore', 'external_path' => 'nps_score'],
            ['field_id' => 'fld_totaltix', 'external_path' => 'total_tickets'],
        ]],
    ];
    $rows = collect(range(0, 9))->map(fn (int $i) => [
        'created_at' => now()->utc()->subDays($i)->toIso8601String(),
        'status' => $i % 2 ? 'open' : 'closed',
        'nps_score' => 6 + ($i % 5),
        'total_tickets' => 10 + $i,
    ])->all();

    $spec = app(DashboardSpecSuggester::class)->suggest($object, 'es', $rows, ['nps', 'yuhu']);

    // A KPI averages nps_score — the measure the user asked for.
    $npsKpi = collect($spec['kpis'])->firstWhere('field_id', 'fld_npsscore');
    expect($npsKpi)->not->toBeNull()
        ->and($npsKpi['aggregation'])->toBe('avg');

    // The trend charts nps_score over time, not a raw ticket count.
    $trend = collect($spec['charts'])->first(fn (array $c): bool => isset($c['x_field_id']));
    expect($trend)->not->toBeNull()
        ->and($trend['y_field_id'] ?? null)->toBe('fld_npsscore')
        ->and($trend['aggregation'])->toBe('avg');
});

it('never charts a recency-capped source as a count-over-time trend', function () {
    $suggester = app(DashboardSpecSuggester::class);

    $countTrend = fn (array $spec): bool => collect($spec['charts'] ?? [])->contains(
        fn (array $c): bool => ($c['aggregation'] ?? null) === 'count' && isset($c['x_field_id']),
    );

    // mode:latest → the volume line is skipped (it would plot the sampling window).
    $capped = $suggester->suggest(dss_comments_object('latest'), 'es', dss_comments_rows());
    expect($countTrend($capped))->toBeFalse();

    // The same object WITHOUT the cap does get the count trend — proving it's the
    // cap that suppresses it, not a missing date field or too few buckets.
    $uncapped = $suggester->suggest(dss_comments_object(null), 'es', dss_comments_rows());
    expect($countTrend($uncapped))->toBeTrue();
});

it('labels a measure KPI with the clean field name, not a "Suma/Promedio" prefix', function () {
    $spec = app(DashboardSpecSuggester::class)->suggest(dss_comments_object(null), 'es', dss_comments_rows());

    $npsKpi = collect($spec['kpis'] ?? [])->firstWhere('field_id', 'fld_npsvalue000');

    expect($npsKpi)->not->toBeNull()
        ->and($npsKpi['label'])->toBe('Nps')
        ->and($npsKpi['label'])->not->toStartWith('Promedio ')
        ->and($npsKpi['label'])->not->toStartWith('Suma ');
});
