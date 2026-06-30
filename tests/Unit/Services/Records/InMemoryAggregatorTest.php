<?php

use App\Services\Records\InMemoryAggregator;

/**
 * The shared in-memory fold used for connected (external) objects. It must agree
 * with the SQL path on every aggregation, including distinct_count and the
 * median/p90/p95 percentiles, and bucket dates the same way.
 *
 * @param  list<array<string, mixed>>  $data
 * @return list<array{id: int, data: array<string, mixed>}>
 */
function rows(array $data): array
{
    return array_values(array_map(fn (int $i, array $d): array => ['id' => $i, 'data' => $d], array_keys($data), $data));
}

beforeEach(function () {
    $this->agg = new InMemoryAggregator;
    // amount: 100,300,200 ; stage: won,won,lost ; customer: ana,ana,beto
    $this->rows = rows([
        ['amount' => 100, 'stage' => 'won', 'customer' => 'ana', 'created' => '2026-01-10'],
        ['amount' => 300, 'stage' => 'won', 'customer' => 'ana', 'created' => '2026-02-15'],
        ['amount' => 200, 'stage' => 'lost', 'customer' => 'beto', 'created' => '2026-02-20'],
    ]);
});

it('counts rows and unique values', function () {
    expect($this->agg->aggregate($this->rows, 'count', null))->toBe(3)
        ->and($this->agg->aggregate($this->rows, 'distinct_count', 'stage'))->toBe(2)
        ->and($this->agg->aggregate($this->rows, 'distinct_count', 'customer'))->toBe(2);
});

it('folds numeric aggregations', function () {
    expect((float) $this->agg->aggregate($this->rows, 'sum', 'amount'))->toBe(600.0)
        ->and((float) $this->agg->aggregate($this->rows, 'avg', 'amount'))->toBe(200.0)
        ->and((float) $this->agg->aggregate($this->rows, 'min', 'amount'))->toBe(100.0)
        ->and((float) $this->agg->aggregate($this->rows, 'max', 'amount'))->toBe(300.0)
        ->and((float) $this->agg->aggregate($this->rows, 'median', 'amount'))->toBe(200.0);
});

it('returns 0 for a numeric fold with no numeric values', function () {
    expect($this->agg->aggregate($this->rows, 'sum', 'stage'))->toBe(0);
});

it('groups a metric by a field', function () {
    $groups = $this->agg->grouped($this->rows, 'sum', 'amount', 'stage', null);
    $byStage = collect($groups)->pluck('value', 'group');

    expect((float) $byStage['won'])->toBe(400.0)   // 100 + 300
        ->and((float) $byStage['lost'])->toBe(200.0);
});

it('pivots across two group dimensions', function () {
    $groups = $this->agg->grouped($this->rows, 'sum', 'amount', 'stage', null, 100, 'customer');
    $matrix = collect($groups)->mapWithKeys(fn ($g) => [$g['group'].'/'.$g['group2'] => $g['value']]);

    expect((float) $matrix['won/ana'])->toBe(400.0)   // 100 + 300, both won+ana
        ->and((float) $matrix['lost/beto'])->toBe(200.0)
        ->and($groups[0])->toHaveKeys(['group', 'group2', 'value']);
});

it('buckets a date group by month', function () {
    $groups = $this->agg->grouped($this->rows, 'count', null, 'created', 'month');
    $byMonth = collect($groups)->pluck('value', 'group');

    expect($byMonth['2026-01'])->toBe(1)
        ->and($byMonth['2026-02'])->toBe(2);
});
