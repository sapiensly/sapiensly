<?php

use App\Models\App;
use App\Models\Integration;
use App\Models\Record;
use App\Models\User;
use App\Services\Records\BlockDataResolver;
use Illuminate\Support\Facades\Http;

/**
 * Builder power #2, runtime wiring — a table block over a connected object renders
 * live external rows through BlockDataResolver (the same seam internal records use),
 * source-agnostic to the frontend. See docs/app-builder-connected-objects-contract.md §4.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->builtApp = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => null]);
    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'draft',
    ]);
});

function dealsManifest(string $integrationId): array
{
    return [
        'objects' => [[
            'id' => 'obj_dealobject',
            'slug' => 'deals',
            'name' => 'Deal',
            'fields' => [
                ['id' => 'fld_namefield', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
            ],
            'source' => [
                'type' => 'connected',
                'integration_id' => $integrationId,
                'id_path' => 'id',
                'operations' => ['list' => ['method' => 'GET', 'path' => '/deals', 'collection_path' => 'results']],
                'field_map' => [['field_id' => 'fld_namefield', 'external_path' => 'properties.dealname']],
            ],
        ]],
    ];
}

/** A connected manifest with numeric + categorical fields, for aggregation KPIs. */
function dealsAggManifest(string $integrationId): array
{
    return [
        'objects' => [[
            'id' => 'obj_dealobject',
            'slug' => 'deals',
            'name' => 'Deal',
            'fields' => [
                ['id' => 'fld_namefield', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                ['id' => 'fld_amountfield', 'slug' => 'amount', 'name' => 'Amount', 'type' => 'currency'],
                ['id' => 'fld_stagefield', 'slug' => 'stage', 'name' => 'Stage', 'type' => 'single_select'],
            ],
            'source' => [
                'type' => 'connected',
                'integration_id' => $integrationId,
                'id_path' => 'id',
                'operations' => ['list' => ['method' => 'GET', 'path' => '/deals', 'collection_path' => 'results']],
                'field_map' => [
                    ['field_id' => 'fld_namefield', 'external_path' => 'properties.dealname'],
                    ['field_id' => 'fld_amountfield', 'external_path' => 'properties.amount'],
                    ['field_id' => 'fld_stagefield', 'external_path' => 'properties.stage'],
                ],
            ],
        ]],
    ];
}

function fakeDeals(): void
{
    Http::fake(['api.example.com/*' => Http::response([
        'results' => [
            ['id' => 'd1', 'properties' => ['dealname' => 'Acme', 'amount' => 100, 'stage' => 'won']],
            ['id' => 'd2', 'properties' => ['dealname' => 'Beta', 'amount' => 300, 'stage' => 'won']],
            ['id' => 'd3', 'properties' => ['dealname' => 'Gamma', 'amount' => 200, 'stage' => 'lost']],
        ],
    ], 200)]);
}

function dealsTableBlock(): array
{
    return ['id' => 'blk_dealstable', 'type' => 'table', 'data_source' => ['object_id' => 'obj_dealobject']];
}

it('renders live external rows for a connected object table block', function () {
    Http::fake(['api.example.com/*' => Http::response([
        'results' => [
            ['id' => 'd1', 'properties' => ['dealname' => 'Acme']],
            ['id' => 'd2', 'properties' => ['dealname' => 'Beta']],
        ],
    ], 200)]);

    $manifest = dealsManifest($this->integration->id);
    $data = app(BlockDataResolver::class)->resolve($this->builtApp, [dealsTableBlock()], $manifest, []);

    expect($data['blk_dealstable']['rows'])->toHaveCount(2)
        ->and($data['blk_dealstable']['rows'][0])->toMatchArray([
            'id' => 'd1',
            'data' => ['name' => 'Acme'],
        ]);

    // Passthrough: the runtime stored nothing internally.
    expect(Record::count())->toBe(0);
});

it('surfaces a block error state when the external read fails', function () {
    Http::fake(['api.example.com/*' => Http::response('down', 502)]);

    $manifest = dealsManifest($this->integration->id);
    $data = app(BlockDataResolver::class)->resolve($this->builtApp, [dealsTableBlock()], $manifest, []);

    expect($data['blk_dealstable'])->toHaveKey('error')
        ->and($data['blk_dealstable'])->not->toHaveKey('rows');
});

it('surfaces an error when the connected integration is missing', function () {
    $manifest = dealsManifest('integ_doesnotexist');
    $data = app(BlockDataResolver::class)->resolve($this->builtApp, [dealsTableBlock()], $manifest, []);

    expect($data['blk_dealstable'])->toHaveKey('error');
});

it('aggregates a stat KPI live in-memory over a connected object', function () {
    fakeDeals();

    $stat = ['id' => 'blk_pipeline', 'type' => 'stat', 'label' => 'Pipeline', 'query' => ['object_id' => 'obj_dealobject'], 'aggregation' => 'sum', 'field_id' => 'fld_amountfield'];
    $data = app(BlockDataResolver::class)->resolve($this->builtApp, [$stat], dealsAggManifest($this->integration->id), []);

    expect((float) $data['blk_pipeline']['value'])->toBe(600.0); // 100 + 300 + 200
    expect(Record::count())->toBe(0); // still passthrough — nothing stored
});

it('computes a live insight figure over a connected object', function () {
    fakeDeals();

    $insight = ['id' => 'blk_insight', 'type' => 'insight', 'variant' => 'conclusion', 'title' => 'Pipeline now', 'compute' => [
        'query' => ['object_id' => 'obj_dealobject'], 'aggregation' => 'sum', 'field_id' => 'fld_amountfield', 'format' => 'currency',
    ]];
    $data = app(BlockDataResolver::class)->resolve($this->builtApp, [$insight], dealsAggManifest($this->integration->id), []);

    expect((float) $data['blk_insight']['value'])->toBe(600.0);
});

it('computes distinct_count and median KPIs over a connected object', function () {
    fakeDeals();

    $grid = ['id' => 'blk_kpis', 'type' => 'metric_grid', 'items' => [
        ['id' => 'itm_stages', 'label' => 'Stages', 'query' => ['object_id' => 'obj_dealobject'], 'aggregation' => 'distinct_count', 'field_id' => 'fld_stagefield'],
        ['id' => 'itm_median', 'label' => 'Median deal', 'query' => ['object_id' => 'obj_dealobject'], 'aggregation' => 'median', 'field_id' => 'fld_amountfield'],
    ]];
    $data = app(BlockDataResolver::class)->resolve($this->builtApp, [$grid], dealsAggManifest($this->integration->id), []);

    expect($data['blk_kpis']['items']['itm_stages']['value'])->toBe(2)      // won, lost
        ->and((float) $data['blk_kpis']['items']['itm_median']['value'])->toBe(200.0); // median of 100,200,300
});
