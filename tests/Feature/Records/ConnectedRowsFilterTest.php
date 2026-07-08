<?php

use App\Models\App;
use App\Models\Integration;
use App\Models\User;
use App\Services\Records\BlockDataResolver;
use App\Services\Tools\McpClient;
use Illuminate\Support\Str;

/**
 * The data-source query must re-scope LIVE connected rows in memory — without
 * this, the dashboard date-range presets were a silent no-op over an MCP-backed
 * connected object (the board showed everything regardless of the preset).
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1',
        'is_mcp' => true,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'draft',
    ]);

    $this->object = [
        'id' => 'obj_livetickets',
        'slug' => 'tickets',
        'name' => 'Ticket',
        'source' => [
            'type' => 'connected',
            'integration_id' => $this->integration->id,
            'id_path' => 'ticket_id',
            'operations' => ['list' => ['mcp_tool' => 'list_tickets', 'collection_path' => 'tickets']],
            'field_map' => [
                ['field_id' => 'fld_datecreated', 'external_path' => 'created_on'],
                ['field_id' => 'fld_minutesfld', 'external_path' => 'minutes'],
            ],
        ],
        'fields' => [
            ['id' => 'fld_datecreated', 'slug' => 'creado', 'name' => 'Creado', 'type' => 'date'],
            ['id' => 'fld_minutesfld', 'slug' => 'minutos', 'name' => 'Minutos', 'type' => 'number'],
        ],
    ];

    $this->manifest = [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'live_'.strtolower(Str::random(6)),
        'name' => 'Live',
        'version' => 1,
        'objects' => [$this->object],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_adminmain', 'slug' => 'admin', 'name' => 'Admin']]],
    ];

    // The MCP tool always returns one recent and one old ticket.
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->andReturn(['tickets' => [
        ['ticket_id' => 'T-recent', 'created_on' => now()->subDays(5)->toDateString(), 'minutes' => 30],
        ['ticket_id' => 'T-old', 'created_on' => now()->subDays(60)->toDateString(), 'minutes' => 90],
    ]]);
    $this->app->instance(McpClient::class, $mcp);

    $this->resolver = app(BlockDataResolver::class);

    $this->rangedSource = [
        'object_id' => 'obj_livetickets',
        'filter' => [
            'op' => 'gte',
            'field_id' => 'fld_datecreated',
            'value_expression' => "{{range_start(default(params.range, '30d'))}}",
        ],
    ];
});

it('narrows live connected rows to the selected date window', function () {
    $block = [
        'id' => 'blk_livetable', 'type' => 'table',
        'data_source' => $this->rangedSource,
        'columns' => [['id' => 'col_datecol', 'field_id' => 'fld_datecreated']],
    ];

    // Default 30d window → only the recent ticket.
    $data = $this->resolver->resolve($this->testApp, [$block], $this->manifest, ['params' => []]);
    expect($data['blk_livetable'])->not->toHaveKey('error')
        ->and($data['blk_livetable']['rows'])->toHaveCount(1)
        ->and($data['blk_livetable']['rows'][0]['id'])->toBe('T-recent');

    // 'all' resolves the range empty → the condition is skipped → full set.
    $data = $this->resolver->resolve($this->testApp, [$block], $this->manifest, ['params' => ['range' => 'all']]);
    expect($data['blk_livetable']['rows'])->toHaveCount(2);
});

it('aggregates KPIs over the filtered live subset', function () {
    $block = [
        'id' => 'blk_livesum', 'type' => 'stat', 'label' => 'Minutos',
        'query' => $this->rangedSource,
        'aggregation' => 'sum', 'field_id' => 'fld_minutesfld',
    ];

    $data = $this->resolver->resolve($this->testApp, [$block], $this->manifest, ['params' => []]);
    expect($data['blk_livesum']['value'])->toBe(30); // recent only

    $data = $this->resolver->resolve($this->testApp, [$block], $this->manifest, ['params' => ['range' => 'all']]);
    expect($data['blk_livesum']['value'])->toBe(120); // both
});

it('filters temporal fields as points in time, whatever format the source uses', function () {
    // Same two tickets, but the external system speaks epoch seconds and prose
    // dates — a lexicographic compare against range_start()'s "YYYY-MM-DD"
    // would silently no-op (every prose date > "2026-…" as a string).
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->andReturn(['tickets' => [
        ['ticket_id' => 'T-epoch-recent', 'created_on' => now()->subDays(3)->getTimestamp(), 'minutes' => 10],
        ['ticket_id' => 'T-prose-old', 'created_on' => now()->subDays(90)->format('F j, Y'), 'minutes' => 20],
    ]]);
    $this->app->instance(McpClient::class, $mcp);
    $resolver = app(BlockDataResolver::class);

    $block = [
        'id' => 'blk_temporaltb', 'type' => 'table',
        'data_source' => $this->rangedSource,
        'columns' => [['id' => 'col_d', 'field_id' => 'fld_datecreated']],
    ];

    $data = $resolver->resolve($this->testApp, [$block], $this->manifest, ['params' => []]);
    expect($data['blk_temporaltb']['rows'])->toHaveCount(1)
        ->and($data['blk_temporaltb']['rows'][0]['id'])->toBe('T-epoch-recent');
});

it('memoises the external read across the blocks of one page', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->once()->andReturn(['tickets' => [
        ['ticket_id' => 'T1', 'created_on' => now()->subDays(2)->toDateString(), 'minutes' => 30],
    ]]);
    $this->app->instance(McpClient::class, $mcp);
    $resolver = app(BlockDataResolver::class);

    $blocks = [
        ['id' => 'blk_memotable1', 'type' => 'table', 'data_source' => $this->rangedSource,
            'columns' => [['id' => 'col_a', 'field_id' => 'fld_datecreated']]],
        ['id' => 'blk_memostat11', 'type' => 'stat', 'label' => 'Total',
            'query' => $this->rangedSource, 'aggregation' => 'count'],
        ['id' => 'blk_memochart1', 'type' => 'chart', 'chart_type' => 'line',
            'x_field_id' => 'fld_datecreated', 'aggregation' => 'count',
            'data_source' => $this->rangedSource],
    ];

    $data = $resolver->resolve($this->testApp, $blocks, $this->manifest, ['params' => []]);
    expect($data['blk_memotable1']['rows'])->toHaveCount(1)
        ->and($data['blk_memostat11']['value'])->toBe(1);
});

it('gives a date_range filter bar the actual span of the governed data', function () {
    $blocks = [
        ['id' => 'blk_rangebar11', 'type' => 'filter_bar',
            'controls' => [['type' => 'date_range', 'param' => 'range', 'default' => '30d']]],
        ['id' => 'blk_spanchart1', 'type' => 'chart', 'chart_type' => 'line',
            'x_field_id' => 'fld_datecreated', 'aggregation' => 'count',
            'data_source' => $this->rangedSource],
    ];

    $data = $this->resolver->resolve($this->testApp, $blocks, $this->manifest, ['params' => ['range' => 'all']]);

    $meta = $data['blk_rangebar11']['date_range'] ?? null;
    expect($meta)->not->toBeNull()
        ->and($meta['param'])->toBe('range')
        ->and($meta['count'])->toBe(2)
        ->and($meta['min'])->toBe(now()->subDays(60)->toDateString())
        ->and($meta['max'])->toBe(now()->subDays(5)->toDateString());
});

it('threads the viewing user to the MCP read so per-user OAuth resolves', function () {
    $seen = null;
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')
        ->andReturnUsing(function ($config, $user, $name, $args, $max = null) use (&$seen) {
            $seen = $user;

            return ['tickets' => []];
        });
    $this->app->instance(McpClient::class, $mcp);
    $resolver = app(BlockDataResolver::class);

    $block = [
        'id' => 'blk_actortable', 'type' => 'table',
        'data_source' => ['object_id' => 'obj_livetickets'],
        'columns' => [['id' => 'col_c', 'field_id' => 'fld_datecreated']],
    ];

    $resolver->resolve($this->testApp, [$block], $this->manifest, ['__actor' => $this->user]);

    expect($seen)->not->toBeNull()
        ->and($seen->is($this->user))->toBeTrue();
});

it('computes the previous-window compare value over live connected rows', function () {
    // The suggester's period-over-period contract: the KPI reads the current
    // window; `compare` re-reads the SAME measure over
    // [range_prev_start, range_start). Recent ticket (5d ago) is the current
    // 30d window; the old one (60d ago) falls exactly in the previous window.
    $block = [
        'id' => 'blk_livecompare', 'type' => 'stat', 'label' => 'Minutos',
        'query' => $this->rangedSource,
        'aggregation' => 'sum', 'field_id' => 'fld_minutesfld',
        'compare' => [
            'object_id' => 'obj_livetickets',
            'filter' => ['op' => 'and', 'conditions' => [
                ['op' => 'gte', 'field_id' => 'fld_datecreated', 'value_expression' => "{{range_prev_start(default(params.range, '30d'))}}"],
                ['op' => 'lt', 'field_id' => 'fld_datecreated', 'value_expression' => "{{range_start(default(params.range, '30d'))}}"],
            ]],
        ],
    ];

    $data = $this->resolver->resolve($this->testApp, [$block], $this->manifest, ['params' => []]);

    expect($data['blk_livecompare']['value'])->toBe(30)          // current window
        ->and($data['blk_livecompare']['compare_value'])->toBe(90); // previous window
});
