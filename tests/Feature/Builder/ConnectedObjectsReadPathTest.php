<?php

use App\Ai\Tools\Builder\SampleEndpointTool;
use App\Models\Integration;
use App\Models\Record;
use App\Models\User;
use App\Services\Connected\ConnectedObjectReader;
use App\Services\Integrations\IntegrationCaller;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * Builder power #2, read-path slice — a connected object reads live external rows
 * through an integration, mapped to its fields, storing nothing locally
 * (passthrough). See docs/app-builder-connected-objects-contract.md §6.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    // Public host so the (real) SSRF guard allows it; the HTTP call is faked.
    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'draft',
    ]);
});

function connectedDealObject(string $integrationId): array
{
    return [
        'id' => 'obj_dealobject',
        'slug' => 'deals',
        'name' => 'Deal',
        'fields' => [
            ['id' => 'fld_namefield', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
            ['id' => 'fld_amountfield', 'slug' => 'amount', 'name' => 'Amount', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'integration_id' => $integrationId,
            'id_path' => 'id',
            'operations' => [
                'list' => ['method' => 'GET', 'path' => '/crm/v3/objects/deals', 'collection_path' => 'results'],
            ],
            'field_map' => [
                ['field_id' => 'fld_namefield', 'external_path' => 'properties.dealname'],
                ['field_id' => 'fld_amountfield', 'external_path' => 'properties.amount'],
            ],
        ],
    ];
}

it('lists external rows mapped to manifest fields, storing nothing', function () {
    Http::fake(['api.example.com/*' => Http::response([
        'results' => [
            ['id' => 'd1', 'properties' => ['dealname' => 'Acme', 'amount' => '1000']],
            ['id' => 'd2', 'properties' => ['dealname' => 'Beta', 'amount' => '2000']],
        ],
    ], 200)]);

    $result = app(ConnectedObjectReader::class)->list(
        connectedDealObject($this->integration->id),
        $this->integration,
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['rows'])->toHaveCount(2)
        ->and($result['rows'][0])->toMatchArray([
            'name' => 'Acme',
            'amount' => '1000',
            '_external_id' => 'd1',
        ]);

    // Passthrough: nothing was written to the internal records store.
    expect(Record::count())->toBe(0);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'api.example.com/crm/v3/objects/deals')
        && $req->hasHeader('Authorization', 'Bearer TKN'));
});

it('leaves unmapped fields null (partial-tolerant)', function () {
    Http::fake(['api.example.com/*' => Http::response([
        'results' => [['id' => 'd1', 'properties' => ['dealname' => 'Acme']]], // no amount
    ], 200)]);

    $result = app(ConnectedObjectReader::class)->list(
        connectedDealObject($this->integration->id),
        $this->integration,
    );

    expect($result['rows'][0]['name'])->toBe('Acme')
        ->and($result['rows'][0]['amount'])->toBeNull();
});

it('degrades to an error result when the external system fails', function () {
    Http::fake(['api.example.com/*' => Http::response('boom', 503)]);

    $result = app(ConnectedObjectReader::class)->list(
        connectedDealObject($this->integration->id),
        $this->integration,
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['rows'])->toBe([])
        ->and($result['error'])->toContain('503');
});

it('sample_endpoint returns the response shape for mapping', function () {
    Http::fake(['api.example.com/*' => Http::response([
        'results' => [['id' => 'd1', 'properties' => ['dealname' => 'Acme']]],
    ], 200)]);

    $tool = new SampleEndpointTool(app(IntegrationCaller::class), $this->user);
    $out = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'path' => '/crm/v3/objects/deals?limit=3',
        'collection_path' => 'results',
    ])), true);

    expect($out['ok'])->toBeTrue()
        ->and($out['status'])->toBe(200)
        ->and($out['row_keys'])->toContain('id', 'properties');
});

it('pushes filter/sort/pagination down to the declared external query params', function () {
    Http::fake(['api.example.com/*' => Http::response(['results' => []], 200)]);

    $object = connectedDealObject($this->integration->id);
    $object['source']['operations']['list'] += [
        'page_param' => 'after',
        'page_size_param' => 'limit',
        'sort_param' => 'sort',
        'order_param' => 'order',
        'filter_params' => [['field_id' => 'fld_namefield', 'param' => 'name']],
    ];

    app(ConnectedObjectReader::class)->list($object, $this->integration, [
        'object_id' => 'obj_dealobject',
        'limit' => 25,
        'offset' => 50,
        'sort' => [['field_id' => 'fld_amountfield', 'direction' => 'desc']],
        'filter' => ['op' => 'eq', 'field_id' => 'fld_namefield', 'value' => 'Acme'],
    ]);

    Http::assertSent(function ($req) {
        $q = [];
        parse_str(parse_url($req->url(), PHP_URL_QUERY) ?? '', $q);

        return ($q['limit'] ?? null) === '25'
            && ($q['after'] ?? null) === '50'
            && ($q['sort'] ?? null) === 'properties.amount'
            && ($q['order'] ?? null) === 'desc'
            && ($q['name'] ?? null) === 'Acme';
    });
});

it('degrades gracefully when a query capability has no declared mapping', function () {
    Http::fake(['api.example.com/*' => Http::response(['results' => []], 200)]);

    // No page/sort/filter params declared on the list op → nothing is pushed down.
    app(ConnectedObjectReader::class)->list(connectedDealObject($this->integration->id), $this->integration, [
        'object_id' => 'obj_dealobject',
        'limit' => 25,
        'sort' => [['field_id' => 'fld_amountfield', 'direction' => 'asc']],
        'filter' => ['op' => 'gt', 'field_id' => 'fld_amountfield', 'value' => 100],
    ]);

    Http::assertSent(fn ($req) => parse_url($req->url(), PHP_URL_QUERY) === null);
});

it('accepts a connected object in the manifest schema', function () {
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_crmapp001',
        'slug' => 'crm',
        'name' => 'CRM',
        'version' => 1,
        'objects' => [connectedDealObject($this->integration->id)],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_adminrole', 'slug' => 'admin', 'name' => 'Admin']]],
    ];

    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});
