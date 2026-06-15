<?php

use App\Models\App;
use App\Models\Integration;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\ManifestValidator;
use App\Services\Runtime\RuntimeAgentToolset;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * Builder power #3, read slice — the runtime agent's toolset is auto-derived from
 * manifest.agent, scoped to the granted objects, and its read tools read live data
 * source-agnostic across internal records and connected objects (power #2). See
 * docs/app-builder-runtime-agent-contract.md §8.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->builtApp = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => null]);
});

function agentManifest(array $agent, ?string $connectedIntegrationId = null): array
{
    $deals = [
        'id' => 'obj_dealobject',
        'slug' => 'deals',
        'name' => 'Deal',
        'fields' => [
            ['id' => 'fld_namefield', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
            ['id' => 'fld_amountfield', 'slug' => 'amount', 'name' => 'Amount', 'type' => 'number'],
        ],
    ];
    if ($connectedIntegrationId !== null) {
        $deals['source'] = [
            'type' => 'connected',
            'integration_id' => $connectedIntegrationId,
            'id_path' => 'id',
            'operations' => ['list' => ['method' => 'GET', 'path' => '/deals', 'collection_path' => 'results']],
            'field_map' => [
                ['field_id' => 'fld_namefield', 'external_path' => 'properties.dealname'],
                ['field_id' => 'fld_amountfield', 'external_path' => 'properties.amount'],
            ],
        ];
    }

    return [
        'schema_version' => '1.0.0',
        'id' => 'app_runtimeagent',
        'slug' => 'crm',
        'name' => 'CRM',
        'version' => 1,
        'objects' => [
            $deals,
            [
                'id' => 'obj_taskobject',
                'slug' => 'tasks',
                'name' => 'Task',
                'fields' => [['id' => 'fld_titlefield', 'slug' => 'title', 'name' => 'Title', 'type' => 'string']],
            ],
        ],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_adminrole', 'slug' => 'admin', 'name' => 'Admin']]],
        'agent' => $agent,
    ];
}

function toolNames(array $tools): array
{
    return array_map(fn ($t) => class_basename($t), $tools);
}

it('derives no tools when the agent is absent or disabled', function () {
    $toolset = app(RuntimeAgentToolset::class);

    expect($toolset->readTools($this->builtApp, agentManifest(['enabled' => false])))->toBe([]);

    $manifest = agentManifest(['enabled' => true]);
    unset($manifest['agent']);
    expect($toolset->readTools($this->builtApp, $manifest))->toBe([]);
});

it('exposes the read tools when capabilities grant read access', function () {
    $tools = app(RuntimeAgentToolset::class)->readTools(
        $this->builtApp,
        agentManifest(['enabled' => true, 'capabilities' => ['read' => 'all']]),
    );

    expect(toolNames($tools))->toEqualCanonicalizing([
        'describe_capabilities', 'query_object', 'aggregate_object',
    ]);
});

it('exposes only describe_capabilities when no objects are granted', function () {
    $tools = app(RuntimeAgentToolset::class)->readTools(
        $this->builtApp,
        agentManifest(['enabled' => true, 'capabilities' => ['read' => []]]),
    );

    expect(toolNames($tools))->toBe(['describe_capabilities']);
});

it('scopes the agent to the granted objects only', function () {
    $manifest = agentManifest(['enabled' => true, 'capabilities' => ['read' => ['obj_dealobject']]]);
    $tools = collect(app(RuntimeAgentToolset::class)->readTools($this->builtApp, $manifest))
        ->keyBy(fn ($t) => class_basename($t));

    // describe lists only the granted object
    $described = json_decode($tools['describe_capabilities']->handle(new ToolRequest([])), true);
    expect($described['objects'])->toHaveCount(1)
        ->and($described['objects'][0]['id'])->toBe('obj_dealobject');

    // querying an ungranted object is refused
    $refused = json_decode($tools['query_object']->handle(new ToolRequest(['object_id' => 'obj_taskobject'])), true);
    expect($refused['error'])->toContain('not available');
});

it('reads internal records through query_object and aggregate_object', function () {
    Record::create(['app_id' => $this->builtApp->id, 'object_definition_id' => 'obj_dealobject', 'organization_id' => null, 'data' => ['name' => 'Acme', 'amount' => 1000]]);
    Record::create(['app_id' => $this->builtApp->id, 'object_definition_id' => 'obj_dealobject', 'organization_id' => null, 'data' => ['name' => 'Beta', 'amount' => 3000]]);

    $tools = collect(app(RuntimeAgentToolset::class)->readTools(
        $this->builtApp,
        agentManifest(['enabled' => true, 'capabilities' => ['read' => 'all']]),
    ))->keyBy(fn ($t) => class_basename($t));

    $rows = json_decode($tools['query_object']->handle(new ToolRequest(['object_id' => 'obj_dealobject'])), true);
    expect($rows['count'])->toBe(2);

    $sum = json_decode($tools['aggregate_object']->handle(new ToolRequest([
        'object_id' => 'obj_dealobject', 'aggregation' => 'sum', 'field_id' => 'fld_amountfield',
    ])), true);
    expect((float) $sum['value'])->toBe(4000.0);
});

it('reads connected objects source-agnostically (live, passthrough)', function () {
    Http::fake(['api.example.com/*' => Http::response([
        'results' => [
            ['id' => 'd1', 'properties' => ['dealname' => 'Acme', 'amount' => '1000']],
            ['id' => 'd2', 'properties' => ['dealname' => 'Beta', 'amount' => '2000']],
        ],
    ], 200)]);

    $integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
    ]);

    $tools = collect(app(RuntimeAgentToolset::class)->readTools(
        $this->builtApp,
        agentManifest(['enabled' => true, 'capabilities' => ['read' => 'all']], $integration->id),
    ))->keyBy(fn ($t) => class_basename($t));

    $rows = json_decode($tools['query_object']->handle(new ToolRequest(['object_id' => 'obj_dealobject'])), true);
    expect($rows['count'])->toBe(2)
        ->and($rows['rows'][0]['data']['name'])->toBe('Acme');

    $count = json_decode($tools['aggregate_object']->handle(new ToolRequest([
        'object_id' => 'obj_dealobject', 'aggregation' => 'count',
    ])), true);
    expect($count['value'])->toBe(2);

    // Passthrough: the agent read stored nothing locally.
    expect(Record::count())->toBe(0);
});

it('accepts the agent block in the manifest schema', function () {
    $manifest = agentManifest([
        'enabled' => true,
        'name' => 'Assistant',
        'instructions' => 'Help the user with their deals.',
        'capabilities' => ['read' => 'all', 'write' => []],
        'autonomy' => 'propose',
    ]);

    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});
