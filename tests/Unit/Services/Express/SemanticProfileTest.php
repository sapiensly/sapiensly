<?php

use App\Services\Express\SemanticProfile;

beforeEach(function () {
    $this->semantics = new SemanticProfile;
});

function sem_object(array $fields, string $collectionPath = 'rows'): array
{
    return [
        'id' => 'obj_x', 'slug' => 'x', 'name' => 'X',
        'fields' => $fields,
        'source' => [
            'operations' => ['list' => ['mcp_tool' => 't', 'collection_path' => $collectionPath]],
            'field_map' => array_map(fn ($f) => ['field_id' => $f['id'], 'external_path' => $f['slug']], $fields),
        ],
    ];
}

it('classifies grain: weekly series, dimension breakdown, raw records', function () {
    $series = sem_object([
        ['id' => 'f1', 'slug' => 'bucket_start', 'name' => 'Semana', 'type' => 'date'],
        ['id' => 'f2', 'slug' => 'total_tickets', 'name' => 'Total', 'type' => 'number'],
    ], 'series');
    expect($this->semantics->grainOf($series))->toBe(SemanticProfile::GRAIN_TIME_SERIES);

    $breakdown = sem_object([
        ['id' => 'f1', 'slug' => 'key', 'name' => 'Key', 'type' => 'string'],
        ['id' => 'f2', 'slug' => 'count', 'name' => 'Count', 'type' => 'number'],
    ], 'by_dimension');
    expect($this->semantics->grainOf($breakdown))->toBe(SemanticProfile::GRAIN_DIMENSION);

    // A percentile table without a date axis is aggregated even without
    // naming conventions (the sellers comparison shape).
    $percentiles = sem_object([
        ['id' => 'f1', 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
        ['id' => 'f2', 'slug' => 'p50_mediana', 'name' => 'P50', 'type' => 'number'],
        ['id' => 'f3', 'slug' => 'p95', 'name' => 'P95', 'type' => 'number'],
    ], 'sellers');
    expect($this->semantics->grainOf($percentiles))->toBe(SemanticProfile::GRAIN_DIMENSION);

    $raw = sem_object([
        ['id' => 'f1', 'slug' => 'status', 'name' => 'Status', 'type' => 'string'],
        ['id' => 'f2', 'slug' => 'created_at', 'name' => 'Creado', 'type' => 'datetime'],
        ['id' => 'f3', 'slug' => 'handle_minutes', 'name' => 'Minutos', 'type' => 'number'],
    ], 'tickets');
    expect($this->semantics->grainOf($raw))->toBe(SemanticProfile::GRAIN_RAW);
});

it('classifies measure types by name and by value shape', function () {
    expect($this->semantics->measureTypeOf(['slug' => 'total_tickets']))->toBe(SemanticProfile::MEASURE_ADDITIVE)
        ->and($this->semantics->measureTypeOf(['slug' => 'otd_pct']))->toBe(SemanticProfile::MEASURE_RATIO)
        ->and($this->semantics->measureTypeOf(['slug' => 'nps']))->toBe(SemanticProfile::MEASURE_RATIO)
        ->and($this->semantics->measureTypeOf(['slug' => 'avg_minutes']))->toBe(SemanticProfile::MEASURE_STATISTIC)
        ->and($this->semantics->measureTypeOf(['slug' => 'p95_minutes']))->toBe(SemanticProfile::MEASURE_STATISTIC)
        ->and($this->semantics->measureTypeOf(['slug' => 'desviacion_estandar']))->toBe(SemanticProfile::MEASURE_STATISTIC);

    // Nameless but 0-100 with decimals → reads as a percentage.
    expect($this->semantics->measureTypeOf(['slug' => 'valor'], [93.4, 88.1, 91.0]))->toBe(SemanticProfile::MEASURE_RATIO);
});

it('enforces the aggregation legality matrix', function () {
    // Percentages are never summable.
    expect($this->semantics->legalKpiAggregations(SemanticProfile::MEASURE_RATIO, SemanticProfile::GRAIN_TIME_SERIES))
        ->not->toContain('sum');
    // Statistics never fold on aggregated grain…
    expect($this->semantics->legalKpiAggregations(SemanticProfile::MEASURE_STATISTIC, SemanticProfile::GRAIN_DIMENSION))
        ->toBe([]);
    // …but a raw column with a statistic-ish name is still a raw measurement.
    expect($this->semantics->legalKpiAggregations(SemanticProfile::MEASURE_STATISTIC, SemanticProfile::GRAIN_RAW))
        ->toContain('median');
    // count(rows) only means anything on raw grain.
    expect($this->semantics->countIsMeaningful(SemanticProfile::GRAIN_RAW))->toBeTrue()
        ->and($this->semantics->countIsMeaningful(SemanticProfile::GRAIN_TIME_SERIES))->toBeFalse();
});

it('computes column stats: distinct, null rate, constants', function () {
    $object = sem_object([
        ['id' => 'f1', 'slug' => 'cat', 'name' => 'Cat', 'type' => 'string'],
        ['id' => 'f2', 'slug' => 'vacio', 'name' => 'Vacío', 'type' => 'string'],
        ['id' => 'f3', 'slug' => 'fijo', 'name' => 'Fijo', 'type' => 'number'],
    ]);
    $rows = [
        ['cat' => 'a', 'vacio' => null, 'fijo' => 5],
        ['cat' => 'b', 'vacio' => null, 'fijo' => 5],
        ['cat' => 'a', 'vacio' => 'x', 'fijo' => 5],
        ['cat' => 'c', 'vacio' => null, 'fijo' => 5],
    ];

    $stats = $this->semantics->columnStats($object, $rows);

    expect($stats['f1']['distinct'])->toBe(3)
        ->and($stats['f2']['null_rate'])->toBe(0.75)
        ->and($stats['f3']['all_equal'])->toBeTrue();
});

it('classifies numeric ids and short score acronyms so they never aggregate wrong', function () {
    // Both observed in production: "Suma Id" (summed contact ids) and
    // "Suma Ces" (summed a 1-7 effort score).
    expect($this->semantics->measureTypeOf(['slug' => 'contact_id']))->toBe(SemanticProfile::MEASURE_IDENTIFIER)
        ->and($this->semantics->measureTypeOf(['slug' => 'id']))->toBe(SemanticProfile::MEASURE_IDENTIFIER)
        ->and($this->semantics->measureTypeOf(['slug' => 'ces']))->toBe(SemanticProfile::MEASURE_RATIO);

    expect($this->semantics->legalKpiAggregations(SemanticProfile::MEASURE_IDENTIFIER, SemanticProfile::GRAIN_RAW))
        ->toBe([]);
});

it('classifies "per-100" ratio fields as ratios by name, not just by value shape', function () {
    // Prod: ratios_tickets_por_100_productos only escaped a sum() because its
    // sampled values happened to carry decimals — with integer samples the
    // value hint stays silent and a per-100 rate would have been summed.
    expect($this->semantics->measureTypeOf(['slug' => 'tickets_por_100_productos']))->toBe(SemanticProfile::MEASURE_RATIO)
        ->and($this->semantics->measureTypeOf(['slug' => 'ratios_tickets_por_100_conversaciones']))->toBe(SemanticProfile::MEASURE_RATIO)
        ->and($this->semantics->measureTypeOf(['slug' => 'items_per_100_orders']))->toBe(SemanticProfile::MEASURE_RATIO);
});

it('reads the percent scale: fractions format as percentage, 0-100 values never do', function () {
    $pct = ['slug' => 'within_target_pct', 'type' => 'number'];

    // 0..1 fractions → the ×100 display format is honest.
    expect($this->semantics->percentScale($pct, [0.93, 0.88, 0.91]))->toBe('fraction');

    // Already on the 0-100 scale → percentage format would show 9300%.
    expect($this->semantics->percentScale($pct, [93.0, 88.5, 91.2]))->toBe('percent');

    // Too few samples, out-of-range (NPS -100..100), or not a ratio → unknown.
    expect($this->semantics->percentScale($pct, [0.9]))->toBeNull()
        ->and($this->semantics->percentScale(['slug' => 'nps'], [45, -20, 60]))->toBeNull()
        ->and($this->semantics->percentScale(['slug' => 'total_tickets'], [10, 20, 30]))->toBeNull();
});

it('names units from slugs and knows which delta direction is good', function () {
    expect($this->semantics->unitOf(['slug' => 'avg_minutes']))->toBe('min')
        ->and($this->semantics->unitOf(['slug' => 'resolution_hours']))->toBe('h')
        ->and($this->semantics->unitOf(['slug' => 'containment_pct']))->toBe('%')
        ->and($this->semantics->unitOf(['slug' => 'total_tickets']))->toBeNull()
        ->and($this->semantics->unitOf(['slug' => 'monto', 'type' => 'currency']))->toBeNull();

    expect($this->semantics->deltaGoodOf(['slug' => 'totals_backlog_open']))->toBe('down')
        ->and($this->semantics->deltaGoodOf(['slug' => 'avg_resolution_minutes']))->toBe('down')
        ->and($this->semantics->deltaGoodOf(['slug' => 'bot_containment_pct']))->toBe('up')
        ->and($this->semantics->deltaGoodOf(['slug' => 'nps_score']))->toBe('up')
        ->and($this->semantics->deltaGoodOf(['slug' => 'total_tickets']))->toBeNull();
});
