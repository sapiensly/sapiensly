<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Records\BlockDataResolver;

/**
 * A chart must plot every record that matches it, not the row window it happened
 * to fetch. Charts used to be handed raw rows and fold them in JavaScript, so a
 * breakdown of sixty tickets over a twelve-row limit charted twelve tickets — and
 * looked entirely confident about it. The grouping now happens where the data
 * lives, and `limit` caps CATEGORIES, which is what it always meant.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->appModel = App::factory()->create(['user_id' => $this->user->id]);

    $this->manifest = [
        'schema_version' => '1.0.0',
        'id' => $this->appModel->id,
        'slug' => 'tickets',
        'name' => 'Tickets',
        'version' => 1,
        'objects' => [[
            'id' => 'obj_agg00000000',
            'slug' => 'tickets',
            'name' => 'Ticket',
            'fields' => [
                ['id' => 'fld_areason000', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
                ['id' => 'fld_astatus000', 'slug' => 'status', 'name' => 'Status', 'type' => 'string'],
                ['id' => 'fld_ahours0000', 'slug' => 'hours', 'name' => 'Hours', 'type' => 'number'],
                ['id' => 'fld_acsat00000', 'slug' => 'csat', 'name' => 'Csat', 'type' => 'number'],
                // A cohort's two dates, and the entity being retained.
                ['id' => 'fld_acust00000', 'slug' => 'customer', 'name' => 'Customer', 'type' => 'string'],
                ['id' => 'fld_asignup000', 'slug' => 'signed_up_at', 'name' => 'Signed Up At', 'type' => 'date'],
                ['id' => 'fld_aorder0000', 'slug' => 'ordered_at', 'name' => 'Ordered At', 'type' => 'date'],
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_agg0000000', 'slug' => 'admin', 'name' => 'Admin']]],
    ];

    // 60 tickets across 3 reasons: 30 shipping, 20 billing, 10 returns — one hour
    // each, so the true sums are 30 / 20 / 10 and the true count is 60. Two of
    // every three are open, and CSAT is constant per reason so the average is
    // unambiguous.
    $csat = ['Envíos' => 70, 'Cobranza' => 90, 'Devoluciones' => 80];

    // Two customers signed up in January and ordered again in February; one signed
    // up in February. Ana orders TWICE in January — which is one retained
    // customer, not two, and that is the difference distinct_count makes.
    $cohorts = [
        ['Ana', '2026-01-05', '2026-01-10'],
        ['Ana', '2026-01-05', '2026-01-22'],
        ['Ana', '2026-01-05', '2026-02-14'],
        ['Beto', '2026-01-18', '2026-01-20'],
        ['Beto', '2026-01-18', '2026-02-02'],
        ['Cris', '2026-02-03', '2026-02-09'],
    ];
    $i = 0;
    foreach (['Envíos' => 30, 'Cobranza' => 20, 'Devoluciones' => 10] as $reason => $n) {
        for ($k = 0; $k < $n; $k++, $i++) {
            [$customer, $signup, $order] = $cohorts[$i % count($cohorts)];
            Record::create([
                'app_id' => $this->appModel->id,
                'object_definition_id' => 'obj_agg00000000',
                'data' => [
                    'reason' => $reason,
                    'status' => $k % 3 === 2 ? 'closed' : 'open',
                    'hours' => 1,
                    'csat' => $csat[$reason],
                    'customer' => $customer,
                    'signed_up_at' => $signup,
                    'ordered_at' => $order,
                ],
            ]);
        }
    }
});

it('sums every record behind a breakdown, not just the fetched rows', function () {
    $blocks = [[
        'id' => 'blk_agg0000000',
        'type' => 'chart',
        'label' => 'Horas por motivo',
        'chart_type' => 'pareto',
        'group_by_field_id' => 'fld_areason000',
        'y_field_id' => 'fld_ahours0000',
        'aggregation' => 'sum',
        // The window a breakdown carries. Before, this fetched 12 of the 60
        // records and charted those; now it caps the number of categories.
        'data_source' => ['object_id' => 'obj_agg00000000', 'limit' => 12],
    ]];

    $data = app(BlockDataResolver::class)->resolve($this->appModel, $blocks, $this->manifest);
    $groups = collect($data['blk_agg0000000']['groups']);

    expect($groups)->toHaveCount(3)
        ->and($groups->firstWhere('group', 'Envíos')['value'])->toEqual(30)
        ->and($groups->firstWhere('group', 'Cobranza')['value'])->toEqual(20)
        ->and($groups->firstWhere('group', 'Devoluciones')['value'])->toEqual(10)
        // The whole point: 60 hours are accounted for, not the 12 that fit.
        ->and($groups->sum('value'))->toEqual(60);
});

it('counts every record, which a row window could never do', function () {
    $blocks = [[
        'id' => 'blk_cnt0000000',
        'type' => 'chart',
        'label' => 'Tickets por motivo',
        'chart_type' => 'donut',
        'group_by_field_id' => 'fld_areason000',
        'aggregation' => 'count',
        'data_source' => ['object_id' => 'obj_agg00000000', 'limit' => 12],
    ]];

    $data = app(BlockDataResolver::class)->resolve($this->appModel, $blocks, $this->manifest);
    $groups = collect($data['blk_cnt0000000']['groups']);

    expect($groups->sum('value'))->toEqual(60)
        ->and($groups->firstWhere('group', 'Envíos')['value'])->toEqual(30);
});

it('a stacked bar resolves as a pivot, aggregated per cell', function () {
    // A second categorical is a pivot, not a second query: {group, group2, value}.
    $blocks = [[
        'id' => 'blk_piv0000000',
        'type' => 'chart',
        'label' => 'Horas por motivo y estado',
        'chart_type' => 'bar',
        'stacked' => true,
        'group_by_field_id' => 'fld_areason000',
        'series_field_id' => 'fld_astatus000',
        'y_field_id' => 'fld_ahours0000',
        'aggregation' => 'sum',
        'data_source' => ['object_id' => 'obj_agg00000000', 'limit' => 12],
    ]];

    $data = app(BlockDataResolver::class)->resolve($this->appModel, $blocks, $this->manifest);
    $groups = collect($data['blk_piv0000000']['groups']);

    // Every cell carries group2, and the whole grid still accounts for all 60.
    expect($groups->every(fn ($g) => array_key_exists('group2', $g)))->toBeTrue()
        ->and($groups->sum('value'))->toEqual(60)
        // Shipping: 20 open + 10 closed (seeded 2:1).
        ->and($groups->first(fn ($g) => $g['group'] === 'Envíos' && $g['group2'] === 'open')['value'])->toEqual(20)
        ->and($groups->first(fn ($g) => $g['group'] === 'Envíos' && $g['group2'] === 'closed')['value'])->toEqual(10);
});

it('a combo aggregates each overlaid measure on its own terms', function () {
    // Volume summed, rate averaged — that is exactly why they cannot share one
    // fold, and why the server returns one grouped series per measure.
    $blocks = [[
        'id' => 'blk_cmb0000000',
        'type' => 'chart',
        'label' => 'Horas vs. satisfacción',
        'chart_type' => 'bar',
        'group_by_field_id' => 'fld_areason000',
        'y_field_id' => 'fld_ahours0000',
        'aggregation' => 'sum',
        'series' => [
            ['type' => 'bar', 'field_id' => 'fld_ahours0000', 'aggregation' => 'sum', 'axis' => 'left'],
            ['type' => 'line', 'field_id' => 'fld_acsat00000', 'aggregation' => 'avg', 'axis' => 'right'],
        ],
        'data_source' => ['object_id' => 'obj_agg00000000', 'limit' => 12],
    ]];

    $data = app(BlockDataResolver::class)->resolve($this->appModel, $blocks, $this->manifest);
    $combo = $data['blk_cmb0000000']['combo'];

    expect($combo)->toHaveCount(2);

    $volume = collect($combo[0]['groups']);
    $rate = collect($combo[1]['groups']);

    // The bars sum every record…
    expect($volume->sum('value'))->toEqual(60)
        ->and($volume->firstWhere('group', 'Envíos')['value'])->toEqual(30)
        // …while the line averages, per category, over those same records.
        ->and((float) $rate->firstWhere('group', 'Envíos')['value'])->toBe(70.0)
        ->and((float) $rate->firstWhere('group', 'Cobranza')['value'])->toBe(90.0);
});

it('a combo overlays series from TWO DIFFERENT objects, each aggregated over its own', function () {
    // The general "compare a metric across two connected sources" case: a series
    // may set its OWN data_source (+ group field), so a combo overlays two
    // separate objects on one X — the resolver aggregates each over its object.
    $manifest = $this->manifest;
    $manifest['objects'][] = [
        'id' => 'obj_esc00000000',
        'slug' => 'escalations',
        'name' => 'Escalation',
        'fields' => [
            ['id' => 'fld_ereason000', 'slug' => 'reason', 'name' => 'Reason', 'type' => 'string'],
            ['id' => 'fld_ecount0000', 'slug' => 'count', 'name' => 'Count', 'type' => 'number'],
        ],
    ];
    foreach ([['Envíos', 4], ['Envíos', 3], ['Cobranza', 5]] as [$reason, $c]) {
        Record::create([
            'app_id' => $this->appModel->id,
            'object_definition_id' => 'obj_esc00000000',
            'data' => ['reason' => $reason, 'count' => $c],
        ]);
    }

    $blocks = [[
        'id' => 'blk_cmbx000000',
        'type' => 'chart',
        'label' => 'Horas (tickets) vs escalaciones',
        'chart_type' => 'bar',
        'group_by_field_id' => 'fld_areason000',
        'data_source' => ['object_id' => 'obj_agg00000000'],
        'series' => [
            // Reads the CHART's object (tickets): sum hours per reason.
            ['type' => 'bar', 'field_id' => 'fld_ahours0000', 'aggregation' => 'sum', 'label' => 'Horas'],
            // Reads a DIFFERENT object with its OWN X field: sum count per reason.
            ['type' => 'line', 'field_id' => 'fld_ecount0000', 'aggregation' => 'sum', 'label' => 'Escalaciones',
                'data_source' => ['object_id' => 'obj_esc00000000'], 'group_by_field_id' => 'fld_ereason000'],
        ],
    ]];

    $data = app(BlockDataResolver::class)->resolve($this->appModel, $blocks, $manifest);
    $combo = $data['blk_cmbx000000']['combo'];

    expect($combo)->toHaveCount(2);
    $hours = collect($combo[0]['groups']);
    $esc = collect($combo[1]['groups']);

    expect($hours->firstWhere('group', 'Envíos')['value'])->toEqual(30)     // tickets object
        ->and($esc->firstWhere('group', 'Envíos')['value'])->toEqual(7)     // escalations object (4+3)
        ->and($esc->firstWhere('group', 'Cobranza')['value'])->toEqual(5);
});

it('a pivot resolves a cohort matrix, bucketing BOTH of its dates', function () {
    // A cohort table's columns are a date too. Left unbucketed, the pivot's second
    // dimension groups by the raw timestamp and every event becomes its own
    // column — a table nobody can read. This is what `column_bucket` is for.
    $blocks = [[
        'id' => 'blk_coh0000000',
        'type' => 'pivot',
        'label' => 'Retención',
        'group_by_field_id' => 'fld_asignup000',
        'bucket' => 'month',
        'column_field_id' => 'fld_aorder0000',
        'column_bucket' => 'month',
        'y_field_id' => 'fld_acust00000',
        'aggregation' => 'distinct_count',
        'mode' => 'cohort',
        'data_source' => ['object_id' => 'obj_agg00000000', 'limit' => 400],
    ]];

    $data = app(BlockDataResolver::class)->resolve($this->appModel, $blocks, $this->manifest);
    $groups = collect($data['blk_coh0000000']['groups']);

    // Both axes came back as months, not as timestamps…
    expect($groups)->not->toBeEmpty()
        ->and($groups->every(fn ($g) => array_key_exists('group2', $g)))->toBeTrue();

    $months = $groups->map(fn ($g) => substr((string) $g['group2'], 0, 7))->unique();
    expect($months->count())->toBeLessThanOrEqual(4)  // a handful of months, not 60 timestamps
        // …and the January intake counts CUSTOMERS, not orders: the two orders
        // Ana placed in January are one retained customer, not two.
        ->and($groups->first(fn ($g) => str_starts_with((string) $g['group'], '2026-01')
            && str_starts_with((string) $g['group2'], '2026-01'))['value'])->toEqual(2);
});

it('leaves the row-level forms their rows — a scatter plots records, not groups', function () {
    $blocks = [[
        'id' => 'blk_sct0000000',
        'type' => 'chart',
        'label' => 'Dispersión',
        'chart_type' => 'scatter',
        'x_field_id' => 'fld_ahours0000',
        'y_field_id' => 'fld_ahours0000',
        'aggregation' => 'sum',
        'data_source' => ['object_id' => 'obj_agg00000000', 'limit' => 500],
    ]];

    $data = app(BlockDataResolver::class)->resolve($this->appModel, $blocks, $this->manifest);

    expect($data['blk_sct0000000'])->toHaveKey('rows')
        ->and($data['blk_sct0000000'])->not->toHaveKey('groups')
        ->and($data['blk_sct0000000']['rows'])->toHaveCount(60);
});
