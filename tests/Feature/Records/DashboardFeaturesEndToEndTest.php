<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\BlockDataResolver;
use Illuminate\Support\Str;

/**
 * End-to-end guard for the dashboard feature set the in-app builder AI and MCP
 * clients author: a single dashboard that exercises EVERY new capability must
 * (a) pass ManifestValidator — so propose_change / validate_manifest accept it —
 * and (b) resolve through BlockDataResolver with NO block erroring — so the
 * runtime renders it. If any feature regresses, this fails loudly.
 */
function did(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

/**
 * @return array{0: array<string,mixed>, 1: string, 2: array<string,string>}
 */
function dashboardManifest(): array
{
    $appId = did('app');
    $deals = did('obj');
    $fName = did('fld');
    $fAmount = did('fld');
    $fStage = did('fld');
    $page = did('pag');

    // Block ids we assert on after resolution.
    $b = [
        'kpis' => did('blk'),
        'combo' => did('blk'),
        'bystage' => did('blk'),
        'insight' => did('blk'),
        'funnel' => did('blk'),
        'gauge' => did('blk'),
    ];
    $kpi = [
        'total' => did('itm'), 'unique' => did('itm'), 'median' => did('itm'),
        'p95' => did('itm'), 'winrate' => did('itm'),
    ];
    $stages = ['all' => did('stg'), 'won' => did('stg')];

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'deals_dash',
        'name' => 'Deals',
        'version' => 1,
        'objects' => [[
            'id' => $deals,
            'slug' => 'deals',
            'name' => 'Deal',
            'fields' => [
                ['id' => $fName, 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                ['id' => $fAmount, 'slug' => 'amount', 'name' => 'Amount', 'type' => 'currency', 'currency_code' => 'MXN'],
                ['id' => $fStage, 'slug' => 'stage', 'name' => 'Stage', 'type' => 'single_select', 'options' => [
                    ['id' => did('opt'), 'value' => 'won', 'label' => 'Won'],
                    ['id' => did('opt'), 'value' => 'lost', 'label' => 'Lost'],
                    ['id' => did('opt'), 'value' => 'open', 'label' => 'Open'],
                ]],
            ],
        ]],
        'pages' => [[
            'id' => $page,
            'slug' => 'dashboard',
            'name' => 'Dashboard',
            'path' => '/',
            'blocks' => [
                // KPI row: count+compare(date helpers), distinct_count, median, p95, ratio.
                ['id' => $b['kpis'], 'type' => 'metric_grid', 'items' => [
                    ['id' => $kpi['total'], 'label' => 'Total', 'query' => ['object_id' => $deals], 'aggregation' => 'count',
                        'compare' => ['object_id' => $deals, 'filter' => ['op' => 'and', 'conditions' => [
                            ['op' => 'gte', 'field_id' => 'sys_created_at', 'value_expression' => '{{start_of_month(1)}}'],
                            ['op' => 'lt', 'field_id' => 'sys_created_at', 'value_expression' => '{{start_of_month()}}'],
                        ]]]],
                    ['id' => $kpi['unique'], 'label' => 'Unique', 'query' => ['object_id' => $deals], 'aggregation' => 'distinct_count', 'field_id' => $fName],
                    ['id' => $kpi['median'], 'label' => 'Median', 'query' => ['object_id' => $deals], 'aggregation' => 'median', 'field_id' => $fAmount, 'format' => 'currency'],
                    ['id' => $kpi['p95'], 'label' => 'P95', 'query' => ['object_id' => $deals], 'aggregation' => 'p95', 'field_id' => $fAmount, 'format' => 'currency'],
                    ['id' => $kpi['winrate'], 'label' => 'Win rate', 'format' => 'percentage',
                        'query' => ['object_id' => $deals, 'filter' => ['op' => 'eq', 'field_id' => $fStage, 'value' => 'won']], 'aggregation' => 'count',
                        'ratio_denominator' => ['query' => ['object_id' => $deals, 'filter' => ['op' => 'in', 'field_id' => $fStage, 'value' => ['won', 'lost']]], 'aggregation' => 'count']],
                ]],
                // Combo chart with a secondary axis + date bucketing on the X.
                ['id' => $b['combo'], 'type' => 'chart', 'chart_type' => 'bar', 'aggregation' => 'count',
                    'data_source' => ['object_id' => $deals], 'group_by_field_id' => 'sys_created_at', 'bucket' => 'month',
                    'series' => [
                        ['type' => 'bar', 'aggregation' => 'sum', 'field_id' => $fAmount, 'label' => 'Revenue', 'axis' => 'left'],
                        ['type' => 'line', 'aggregation' => 'count', 'label' => 'Deals', 'axis' => 'right'],
                    ]],
                ['id' => $b['bystage'], 'type' => 'chart', 'chart_type' => 'donut', 'aggregation' => 'count',
                    'data_source' => ['object_id' => $deals], 'group_by_field_id' => $fStage],
                // Computed insight (live figure + trend).
                ['id' => $b['insight'], 'type' => 'insight', 'variant' => 'conclusion', 'title' => 'Pipeline',
                    'compute' => ['query' => ['object_id' => $deals], 'aggregation' => 'sum', 'field_id' => $fAmount, 'format' => 'currency', 'delta_good' => 'up',
                        'compare' => ['object_id' => $deals, 'filter' => ['op' => 'gte', 'field_id' => 'sys_created_at', 'value_expression' => '{{start_of_month(1)}}']]]],
                ['id' => $b['funnel'], 'type' => 'funnel', 'stages' => [
                    ['id' => $stages['all'], 'label' => 'All', 'query' => ['object_id' => $deals], 'aggregation' => 'count'],
                    ['id' => $stages['won'], 'label' => 'Won', 'query' => ['object_id' => $deals, 'filter' => ['op' => 'eq', 'field_id' => $fStage, 'value' => 'won']], 'aggregation' => 'count'],
                ]],
                ['id' => $b['gauge'], 'type' => 'gauge', 'label' => 'Distinct names', 'query' => ['object_id' => $deals], 'aggregation' => 'distinct_count', 'field_id' => $fName, 'max_value' => 100],
            ],
        ]],
        'permissions' => ['roles' => [['id' => did('rol'), 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    return [$manifest, $deals, $b + ['kpi' => $kpi, 'stages' => $stages]];
}

it('the manifest validator accepts a dashboard using every dashboard feature', function () {
    [$manifest] = dashboardManifest();

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeTrue()
        ->and($result->errors)->toBe([]);
});

it('the runtime resolves every dashboard feature with no block erroring', function () {
    [$manifest, $deals, $b] = dashboardManifest();

    $user = User::factory()->create();
    $app = App::factory()->create(['user_id' => $user->id, 'slug' => 'deals_dash']);
    foreach ([['a', 100, 'won'], ['b', 200, 'won'], ['c', 300, 'lost'], ['d', 400, 'open']] as [$name, $amount, $stage]) {
        Record::create(['app_id' => $app->id, 'object_definition_id' => $deals, 'data' => ['name' => $name, 'amount' => $amount, 'stage' => $stage]]);
    }

    $data = app(BlockDataResolver::class)->resolve($app, $manifest['pages'][0]['blocks'], $manifest);

    // No block (or metric_grid item) resolved to an error.
    foreach ([$b['kpis'], $b['combo'], $b['bystage'], $b['insight'], $b['funnel'], $b['gauge']] as $id) {
        expect($data[$id] ?? [])->not->toHaveKey('error');
    }
    foreach ($data[$b['kpis']]['items'] as $item) {
        expect($item)->not->toHaveKey('error');
    }

    // Spot-check the computed values prove the features actually ran.
    $items = $data[$b['kpis']]['items'];
    expect($items[$b['kpi']['total']]['value'])->toBe(4)
        ->and($items[$b['kpi']['unique']]['value'])->toBe(4)              // distinct names
        ->and((float) $items[$b['kpi']['median']]['value'])->toBe(250.0) // median of 100..400
        ->and((float) $items[$b['kpi']['p95']]['value'])->toBe(385.0)    // p95 of 100..400
        ->and(round((float) $items[$b['kpi']['winrate']]['value'], 4))->toBe(0.6667); // 2 won / 3 closed

    // A combo resolves to one GROUPED series per overlaid measure, each with its
    // own aggregation — revenue summed on the left axis, deals counted on the
    // right — rather than raw rows the client folds.
    $combo = $data[$b['combo']]['combo'];
    expect($combo)->toHaveCount(2)
        ->and((float) collect($combo[0]['groups'])->sum('value'))->toBe(1000.0) // sum(amount)
        ->and((int) collect($combo[1]['groups'])->sum('value'))->toBe(4)        // count(deals)
        ->and((float) $data[$b['insight']]['value'])->toBe(1000.0)     // computed insight: sum amount
        ->and($data[$b['funnel']]['stages'][$b['stages']['won']]['value'])->toBe(2)
        ->and($data[$b['gauge']]['value'])->toBe(4);
});
