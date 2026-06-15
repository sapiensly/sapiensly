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
