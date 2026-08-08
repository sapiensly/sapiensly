<?php

use App\Ai\Tools\Builder\AddConnectedObjectTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Models\App;
use App\Models\Integration;
use App\Models\User;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use App\Services\Tools\McpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * The same one-call path, for a REST connection.
 *
 * It existed only for MCP, and the asymmetry WAS the problem: a REST source had
 * to be hand-written as a propose_change — twenty fields, a field_map and an
 * id_path composed by the model — which is the largest rejection class in the
 * build ledger. Everything after the fetch (typing the fields, proving a rate is
 * derived, spotting an unresolved tail) is a fact about the data rather than the
 * transport, so both paths share it; these tests exist to keep that true.
 */
function acr_manifest(string $appId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'rest_'.strtolower(Str::random(6)),
        'name' => 'Rest',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->manifestService = app(AppManifestService::class);
    $this->manifestService->createVersion($this->testApp, acr_manifest($this->testApp->id), $this->user);

    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
        'is_mcp' => false,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'active',
    ]);
});

function acr_tool($test): array
{
    $propose = new ProposeChangeTool($test->testApp->fresh(), $test->manifestService, app(ManifestValidator::class));
    $authoring = app(ConnectedObjectAuthoring::class);

    return [new AddConnectedObjectTool($propose, $authoring, $test->user), $propose];
}

it('models and banks a connected object from one REST call', function () {
    Http::fake(['https://api.example.com/*' => Http::response([
        'results' => [
            ['id' => 'D-1', 'nombre' => 'Uno', 'monto' => 1200, 'cerrado' => true, 'creado' => '2026-07-01'],
            ['id' => 'D-2', 'nombre' => 'Dos', 'monto' => 340, 'cerrado' => false, 'creado' => '2026-07-02'],
        ],
    ], 200)]);

    [$tool, $propose] = acr_tool($this);

    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'path' => '/crm/v3/objects/deals?limit=50',
        'object_name' => 'Negocios',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['sampled_rows'])->toBe(2);

    $object = collect($propose->currentManifest()['objects'])->firstWhere('name', 'Negocios');

    expect($object)->not->toBeNull()
        ->and($object['source']['type'])->toBe('connected')
        ->and($object['source']['integration_id'])->toBe($this->integration->id)
        // The list operation is REST-shaped: method + path, never an mcp_tool.
        ->and($object['source']['operations']['list'])
        ->toMatchArray(['method' => 'GET', 'path' => '/crm/v3/objects/deals?limit=50', 'collection_path' => 'results'])
        ->and($object['source']['operations']['list'])->not->toHaveKey('mcp_tool')
        // The id and the field types come from the real rows, not from the model.
        ->and($object['source']['id_path'])->toBe('id');

    $types = collect($object['fields'])->pluck('type', 'slug')->all();
    expect($types)->toMatchArray([
        'nombre' => 'string',
        'monto' => 'number',
        'cerrado' => 'boolean',
        'creado' => 'date',
    ]);
});

it('auto-detects the row array when no collection_path is given', function () {
    Http::fake(['https://api.example.com/*' => Http::response([
        'data' => ['items' => [['id' => 1, 'sku' => 'A-1'], ['id' => 2, 'sku' => 'A-2']]],
    ], 200)]);

    [$tool, $propose] = acr_tool($this);

    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'path' => '/inventory',
    ])), true);

    expect($result['ok'])->toBeTrue();

    $object = collect($propose->currentManifest()['objects'])->first();
    expect($object['source']['operations']['list']['collection_path'])->toBe('data.items');
});

it('reports the failure instead of banking an object that would error on every page load', function () {
    // The read IS the verification. A 404 authored anyway becomes a block that
    // fails for ever, discovered by whoever opens the app rather than here.
    Http::fake(['https://api.example.com/*' => Http::response(['error' => 'nope'], 404)]);

    [$tool, $propose] = acr_tool($this);

    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'path' => '/wrong',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('404')
        ->and($propose->currentManifest()['objects'])->toBe([]);
});

it('refuses an endpoint with nothing to model, naming what came back', function () {
    Http::fake(['https://api.example.com/*' => Http::response(['total' => 0, 'page' => 1], 200)]);

    [$tool] = acr_tool($this);

    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'path' => '/empty',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('no rows')
        // Naming the keys is what lets the next attempt pick a real path.
        ->and($result['errors'][0]['message'])->toContain('total');
});

it('refuses to author a list operation out of a verb that changes something', function () {
    // Authoring reads the endpoint for real, so accepting DELETE here would make
    // "create an object" delete something.
    [$tool] = acr_tool($this);

    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'path' => '/orders/1',
        'method' => 'DELETE',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('A list operation reads');
});

it('carries the data rules across from the MCP path rather than leaving them behind', function () {
    // A rate the rows PROVE is derived must be stamped whichever transport
    // fetched them — written twice, the REST path would have shipped without it
    // and a dashboard would have averaged a percentage.
    $rows = [];
    foreach (range(1, 12) as $i) {
        $total = $i * 10;
        $late = $i;
        $rows[] = [
            'id' => "D-{$i}",
            'fecha' => sprintf('2026-06-%02d', $i),
            'total' => $total,
            'late' => $late,
            'otd_pct' => round(($total - $late) / $total, 4),
        ];
    }
    Http::fake(['https://api.example.com/*' => Http::response(['rows' => $rows], 200)]);

    [$tool] = acr_tool($this);

    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'path' => '/otd',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result)->toHaveKeys(['derived_rates', 'immature_periods', 'date_field_ids'])
        ->and($result['derived_rates'])->not->toBeEmpty();
});

it('never reaches for an MCP client on the REST path', function () {
    // A strict mock with no expectations: touching MCP here fails the test.
    $this->app->instance(McpClient::class, Mockery::mock(McpClient::class));

    Http::fake(['https://api.example.com/*' => Http::response(['rows' => [['id' => 1, 'a' => 'x']]], 200)]);

    [$tool] = acr_tool($this);

    $result = json_decode($tool->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'path' => '/things',
    ])), true);

    expect($result['ok'])->toBeTrue();
});
