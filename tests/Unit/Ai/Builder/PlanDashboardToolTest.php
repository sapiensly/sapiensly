<?php

use App\Ai\Tools\Builder\PlanDashboardTool;
use Laravel\Ai\Tools\Request;

/**
 * The dashboard planning lints: deterministic checks that force the building
 * model through a real planning stage (KPI band first, balanced rows, chart
 * variety, insight conclusions) before it may propose dashboard blocks.
 */
function planDashboard(array $args): array
{
    return json_decode((new PlanDashboardTool)->handle(new Request($args)), true);
}

function goodPlan(): array
{
    return [
        'purpose' => 'Operations lead tracks warranty volume, SLA and drivers; headline numbers first.',
        'rows' => [
            ['blocks' => [['type' => 'metric_grid']]],
            ['section' => 'Tendencia', 'blocks' => [
                ['type' => 'chart', 'chart_type' => 'line', 'col_span' => 7],
                ['type' => 'chart', 'chart_type' => 'donut', 'col_span' => 3],
            ]],
            ['section' => 'Desglose', 'blocks' => [
                ['type' => 'chart', 'chart_type' => 'hbar'],
                ['type' => 'chart', 'chart_type' => 'box'],
            ]],
            ['section' => 'Detalle', 'blocks' => [['type' => 'table']]],
            ['section' => 'Lecturas', 'blocks' => [
                ['type' => 'insight'], ['type' => 'insight'], ['type' => 'insight'],
            ]],
        ],
    ];
}

it('approves a well-formed plan', function () {
    $res = planDashboard(goodPlan());

    expect($res['ok'])->toBeTrue()
        ->and($res['issues'])->toBe([]);
});

it('requires a KPI metric_grid row near the top', function () {
    $plan = goodPlan();
    array_shift($plan['rows']); // drop the KPI row

    $res = planDashboard($plan);

    expect($res['ok'])->toBeFalse()
        ->and(implode(' ', $res['issues']))->toContain('KPI');
});

it('rejects repeating the same chart form three times', function () {
    $plan = goodPlan();
    $plan['rows'][2]['blocks'] = [
        ['type' => 'chart', 'chart_type' => 'donut'],
        ['type' => 'chart', 'chart_type' => 'donut'],
    ];

    $res = planDashboard($plan);

    expect($res['ok'])->toBeFalse()
        ->and(implode(' ', $res['issues']))->toContain("'donut' appears 3");
});

it('rejects a lone short block leaving the row empty', function () {
    $plan = goodPlan();
    $plan['rows'][] = ['blocks' => [['type' => 'chart', 'chart_type' => 'pie']]];

    $res = planDashboard($plan);

    expect($res['ok'])->toBeFalse()
        ->and(implode(' ', $res['issues']))->toContain('single short block');
});

it('requires insight conclusions', function () {
    $plan = goodPlan();
    array_pop($plan['rows']); // drop the insights row

    $res = planDashboard($plan);

    expect($res['ok'])->toBeFalse()
        ->and(implode(' ', $res['issues']))->toContain('insight');
});

it('rejects a metric_grid sharing a row and unknown chart types', function () {
    $plan = goodPlan();
    $plan['rows'][0]['blocks'][] = ['type' => 'chart', 'chart_type' => 'spiral'];

    $res = planDashboard($plan);
    $all = implode(' ', $res['issues']);

    expect($res['ok'])->toBeFalse()
        ->and($all)->toContain('full-width KPI band')
        ->and($all)->toContain("unknown chart_type 'spiral'");
});

it('hints col_span for a wide + short pair without weights', function () {
    $plan = goodPlan();
    unset($plan['rows'][1]['blocks'][0]['col_span'], $plan['rows'][1]['blocks'][1]['col_span']);

    $res = planDashboard($plan);

    expect($res['ok'])->toBeTrue()
        ->and(implode(' ', $res['hints']))->toContain('col_span');
});
