<?php

use App\Models\App;
use App\Models\Integration;
use App\Models\Record;
use App\Services\Connected\ConnectedIntegrationResolver;
use App\Services\Connected\ConnectedObjectReader;
use App\Services\Records\BlockDataResolver;
use Illuminate\Support\Str;

/**
 * BlockDataResolver must NEVER throw because of one broken block — that crash
 * takes down the whole Builder preview / runtime page. Instead, each block's
 * resolution is wrapped in try/catch and the error is surfaced as
 * blockData[id].error so the renderer can paint a placeholder.
 */
beforeEach(function () {
    $this->resolver = app(BlockDataResolver::class);
    $this->testApp = App::factory()->create();
    $this->nameField = [
        'id' => 'fld_'.strtolower((string) Str::ulid()),
        'slug' => 'nombre',
        'name' => 'Nombre',
        'type' => 'string',
    ];
    $this->amountField = [
        'id' => 'fld_'.strtolower((string) Str::ulid()),
        'slug' => 'monto',
        'name' => 'Monto',
        'type' => 'currency',
        'currency_code' => 'MXN',
    ];
    $this->object = [
        'id' => 'obj_'.strtolower((string) Str::ulid()),
        'slug' => 'cliente',
        'name' => 'Cliente',
        'fields' => [$this->nameField, $this->amountField],
    ];
    $this->manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_'.strtolower((string) Str::ulid()),
        'slug' => 'test_app',
        'name' => 'Test',
        'version' => 1,
        'objects' => [$this->object],
        'pages' => [],
        'permissions' => [
            'roles' => [
                ['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin'],
            ],
        ],
    ];

    Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $this->object['id'],
        'data' => ['nombre' => 'Ana', 'monto' => 100],
    ]);
});

it('resolves a table block and a stat block side by side', function () {
    $blocks = [
        [
            'id' => 'blk_table',
            'type' => 'table',
            'data_source' => ['object_id' => $this->object['id']],
            'columns' => [['id' => 'col_a', 'field_id' => $this->nameField['id']]],
        ],
        [
            'id' => 'blk_stat',
            'type' => 'stat',
            'label' => 'Total',
            'query' => ['object_id' => $this->object['id']],
            'aggregation' => 'sum',
            'field_id' => $this->amountField['id'],
        ],
    ];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data)->toHaveKeys(['blk_table', 'blk_stat'])
        ->and($data['blk_table']['rows'])->toHaveCount(1)
        ->and($data['blk_stat']['value'])->toBe(100.0);
});

it('computes a live figure and comparison for an insight block', function () {
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->object['id'], 'data' => ['nombre' => 'Beto', 'monto' => 300]]);

    $blocks = [[
        'id' => 'blk_insight',
        'type' => 'insight',
        'variant' => 'conclusion',
        'title' => 'Pipeline',
        'compute' => [
            'query' => ['object_id' => $this->object['id']],
            'aggregation' => 'sum',
            'field_id' => $this->amountField['id'],
            'compare' => ['object_id' => $this->object['id'], 'filter' => ['op' => 'eq', 'field_id' => $this->nameField['id'], 'value' => 'Nobody']],
            'delta_good' => 'up',
        ],
    ]];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect((float) $data['blk_insight']['value'])->toBe(400.0)        // 100 + 300
        ->and((float) $data['blk_insight']['compare_value'])->toBe(0.0); // nothing matches "Nobody"
});

it('computes a ratio KPI (numerator over denominator)', function () {
    // 4 records, 1 of them "Ana" → ratio of Ana-records to all = 0.25.
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->object['id'], 'data' => ['nombre' => 'Beto', 'monto' => 0]]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->object['id'], 'data' => ['nombre' => 'Caro', 'monto' => 0]]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $this->object['id'], 'data' => ['nombre' => 'Dani', 'monto' => 0]]);

    $blocks = [[
        'id' => 'blk_rate',
        'type' => 'stat',
        'label' => 'Ana share',
        'format' => 'percentage',
        'query' => ['object_id' => $this->object['id'], 'filter' => ['op' => 'eq', 'field_id' => $this->nameField['id'], 'value' => 'Ana']],
        'aggregation' => 'count',
        'ratio_denominator' => ['query' => ['object_id' => $this->object['id']], 'aggregation' => 'count'],
    ]];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data['blk_rate']['value'])->toBe(0.25)            // 1 Ana / 4 total
        ->and($data['blk_rate'])->not->toHaveKey('compare_value'); // ratios omit the trend chip
});

it('guards a ratio KPI against division by zero', function () {
    $blocks = [[
        'id' => 'blk_zero',
        'type' => 'stat',
        'label' => 'Empty rate',
        'query' => ['object_id' => $this->object['id']],
        'aggregation' => 'count',
        'ratio_denominator' => ['query' => ['object_id' => $this->object['id'], 'filter' => ['op' => 'eq', 'field_id' => $this->nameField['id'], 'value' => 'Nobody']], 'aggregation' => 'count'],
    ]];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data['blk_zero']['value'])->toBe(0); // denominator 0 → 0, not a crash
});

it('returns no server data for a static insight with no compute', function () {
    $blocks = [['id' => 'blk_static', 'type' => 'insight', 'title' => 'Note', 'body' => 'Just text', 'metric' => '+34%']];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data)->not->toHaveKey('blk_static'); // null payload → not in the map
});

it('does not crash when a stat block references a missing field_id', function () {
    $blocks = [
        [
            'id' => 'blk_ok',
            'type' => 'table',
            'data_source' => ['object_id' => $this->object['id']],
            'columns' => [['id' => 'col_a', 'field_id' => $this->nameField['id']]],
        ],
        [
            'id' => 'blk_broken',
            'type' => 'stat',
            'label' => 'Bad',
            'query' => ['object_id' => $this->object['id']],
            'aggregation' => 'sum',
            'field_id' => 'fld_does_not_exist',
        ],
    ];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    // The healthy block still resolves.
    expect($data)->toHaveKey('blk_ok')
        ->and($data['blk_ok']['rows'])->toHaveCount(1);

    // The broken block surfaces its error rather than crashing.
    expect($data)->toHaveKey('blk_broken')
        ->and($data['blk_broken'])->toHaveKey('error')
        ->and($data['blk_broken']['error'])->toContain('fld_does_not_exist');
});

it('does not crash when a table block references a missing object_id', function () {
    $blocks = [
        [
            'id' => 'blk_broken',
            'type' => 'table',
            'data_source' => ['object_id' => 'obj_does_not_exist'],
            'columns' => [['id' => 'col_a', 'field_id' => $this->nameField['id']]],
        ],
    ];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data)->toHaveKey('blk_broken')
        ->and($data['blk_broken'])->toHaveKey('error');
});

it('isolates errors inside metric_grid items so good items still resolve', function () {
    $blocks = [
        [
            'id' => 'blk_grid',
            'type' => 'metric_grid',
            'columns' => 2,
            'items' => [
                [
                    'id' => 'item_ok',
                    'label' => 'Good',
                    'query' => ['object_id' => $this->object['id']],
                    'aggregation' => 'sum',
                    'field_id' => $this->amountField['id'],
                ],
                [
                    'id' => 'item_bad',
                    'label' => 'Broken',
                    'query' => ['object_id' => $this->object['id']],
                    'aggregation' => 'sum',
                    'field_id' => 'fld_does_not_exist',
                ],
            ],
        ],
    ];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data['blk_grid']['items']['item_ok']['value'])->toBe(100.0)
        ->and($data['blk_grid']['items']['item_bad'])->toHaveKey('error');
});

it('pre-resolves form field default and readonly expressions server-side', function () {
    $blocks = [[
        'id' => 'blk_form',
        'type' => 'form',
        'object_id' => $this->object['id'],
        'mode' => 'create',
        'fields' => [
            ['field_id' => $this->nameField['id'], 'default_expression' => '{{today()}}'],
            ['field_id' => $this->amountField['id'], 'readonly_expression' => '{{true}}'],
        ],
    ]];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data['blk_form']['form']['defaults']['nombre'])->toBe(now()->utc()->toDateString())
        ->and($data['blk_form']['form']['readonly']['monto'])->toBeTrue();
});

it('resolves multi_step_form field expressions from a context value', function () {
    $blocks = [[
        'id' => 'blk_msf',
        'type' => 'multi_step_form',
        'object_id' => $this->object['id'],
        'mode' => 'create',
        'steps' => [
            ['id' => 'stp_1', 'title' => 'One', 'fields' => [
                ['field_id' => $this->nameField['id'], 'default_expression' => '{{current_user.email}}'],
            ]],
        ],
    ]];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest, [
        'current_user' => ['id' => 'usr_1', 'email' => 'ana@example.com'],
    ]);

    expect($data['blk_msf']['form']['defaults']['nombre'])->toBe('ana@example.com');
});

it('returns no form entry when a form has no field expressions', function () {
    $blocks = [[
        'id' => 'blk_plain',
        'type' => 'form',
        'object_id' => $this->object['id'],
        'mode' => 'create',
        'fields' => [['field_id' => $this->nameField['id']]],
    ]];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data)->not->toHaveKey('blk_plain');
});

it('recurses through layout blocks and tolerates broken descendants', function () {
    $blocks = [
        [
            'id' => 'blk_tabs',
            'type' => 'tabs',
            'tabs' => [
                [
                    'id' => 'tab_a',
                    'label' => 'A',
                    'blocks' => [
                        [
                            'id' => 'blk_table',
                            'type' => 'table',
                            'data_source' => ['object_id' => $this->object['id']],
                            'columns' => [['id' => 'col_a', 'field_id' => $this->nameField['id']]],
                        ],
                        [
                            'id' => 'blk_broken_stat',
                            'type' => 'stat',
                            'label' => 'Bad',
                            'query' => ['object_id' => $this->object['id']],
                            'aggregation' => 'sum',
                            'field_id' => 'fld_does_not_exist',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data['blk_table']['rows'])->toHaveCount(1)
        ->and($data['blk_broken_stat'])->toHaveKey('error');
});

it('attaches spark_rows to a stat that carries an inline sparkline', function () {
    Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $this->object['id'],
        'data' => ['nombre' => 'Beto', 'monto' => 250],
    ]);

    $blocks = [
        [
            'id' => 'blk_statspark',
            'type' => 'stat',
            'label' => 'Total',
            'query' => ['object_id' => $this->object['id']],
            'aggregation' => 'sum',
            'field_id' => $this->amountField['id'],
            'spark' => [
                'data_source' => ['object_id' => $this->object['id']],
                'y_field_id' => $this->amountField['id'],
                'aggregation' => 'sum',
            ],
        ],
    ];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data['blk_statspark']['value'])->toBe(350.0)
        ->and($data['blk_statspark']['spark_rows'])->toHaveCount(2);
});

it('attaches spark_rows to a metric_grid item that carries an inline sparkline', function () {
    $blocks = [
        [
            'id' => 'blk_mg',
            'type' => 'metric_grid',
            'items' => [
                [
                    'id' => 'itm_total',
                    'label' => 'Total',
                    'query' => ['object_id' => $this->object['id']],
                    'aggregation' => 'count',
                    'spark' => ['data_source' => ['object_id' => $this->object['id']]],
                ],
            ],
        ],
    ];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data['blk_mg']['items']['itm_total']['spark_rows'])->toHaveCount(1);
});

it('keeps the KPI even when its spark query is broken (fails soft)', function () {
    $blocks = [
        [
            'id' => 'blk_softspark',
            'type' => 'stat',
            'label' => 'Total',
            'query' => ['object_id' => $this->object['id']],
            'aggregation' => 'count',
            'spark' => ['data_source' => ['object_id' => 'obj_missing']],
        ],
    ];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest);

    expect($data['blk_softspark'])->toHaveKey('value')
        ->and($data['blk_softspark'])->not->toHaveKey('error');
});

it('a date_range filter (range_start) narrows a block to the selected window', function () {
    // Backdate one record beyond the default 30-day window; beforeEach's "Ana"
    // stays recent. Force created_at at the DB layer (Eloquent overrides it on
    // create).
    $old = Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $this->object['id'],
        'data' => ['nombre' => 'Vieja', 'monto' => 10],
    ]);
    Record::where('id', $old->id)->update(['created_at' => now()->subDays(60)]);

    $expr = "{{range_start(default(params.range, '30d'))}}";
    $block = [
        'id' => 'blk_ranged',
        'type' => 'table',
        'data_source' => [
            'object_id' => $this->object['id'],
            'filter' => ['op' => 'gte', 'field_id' => 'sys_created_at', 'value_expression' => $expr],
        ],
        'columns' => [['id' => 'col_a', 'field_id' => $this->nameField['id']]],
    ];

    // Default window (30d) → only the recent record.
    $data = $this->resolver->resolve($this->testApp, [$block], $this->manifest, ['params' => []]);
    expect($data['blk_ranged'])->not->toHaveKey('error')
        ->and($data['blk_ranged']['rows'])->toHaveCount(1);

    // 'all' clears the filter → both records show.
    $data = $this->resolver->resolve($this->testApp, [$block], $this->manifest, ['params' => ['range' => 'all']]);
    expect($data['blk_ranged']['rows'])->toHaveCount(2);
});

it('threads the page date_range control to connected reads as __page_range_start_expr', function () {
    // The other half of the frozen-window fix: resolve() derives the page's
    // governing range expression from its filter_bar and threads it in the
    // context, so ConnectedObjectReader can widen a date-less block's fetch
    // window (see ConnectedObjectsMcpReadTest for the reader half).
    $connectedObject = [
        'id' => 'obj_'.strtolower((string) Str::ulid()),
        'slug' => 'nps_by_dimension',
        'name' => 'Nps By Dimension',
        'fields' => [
            ['id' => 'fld_dimkey00000', 'slug' => 'key', 'name' => 'Key', 'type' => 'string'],
            ['id' => 'fld_dimpassives', 'slug' => 'passives', 'name' => 'Passives', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'integration_id' => 'integ_fake',
            'operations' => ['list' => ['mcp_tool' => 'get-nps-by-dimension-tool', 'collection_path' => 'breakdown']],
        ],
    ];
    $manifest = array_merge($this->manifest, ['objects' => [$connectedObject]]);

    $integration = Integration::factory()->create(['is_mcp' => true]);
    $integrations = Mockery::mock(ConnectedIntegrationResolver::class);
    $integrations->shouldReceive('resolve')->andReturn($integration);

    $reader = Mockery::mock(ConnectedObjectReader::class);
    $reader->shouldReceive('list')
        ->once()
        ->withArgs(fn ($object, $integ, $query, $actor, $context) => ($context['__page_range_start_expr'] ?? null) === "{{range_start(default(params.range, '90d'))}}")
        ->andReturn(['ok' => true, 'rows' => [['_external_id' => 'x', 'key' => 'Salud', 'passives' => 7]]]);

    $this->app->instance(ConnectedObjectReader::class, $reader);
    $this->app->instance(ConnectedIntegrationResolver::class, $integrations);
    $resolver = app(BlockDataResolver::class);

    $blocks = [
        ['id' => 'blk_fbar', 'type' => 'filter_bar', 'controls' => [['type' => 'date_range', 'param' => 'range', 'default' => '90d']]],
        ['id' => 'blk_grid', 'type' => 'metric_grid', 'items' => [[
            'id' => 'itm_passives00',
            'label' => 'Passives',
            'field_id' => 'fld_dimpassives',
            'aggregation' => 'sum',
            'query' => ['object_id' => $connectedObject['id']],
        ]]],
    ];

    $data = $resolver->resolve($this->testApp, $blocks, $manifest, ['params' => []]);

    expect($data['blk_grid']['items']['itm_passives00']['value'])->toBe(7);
});
