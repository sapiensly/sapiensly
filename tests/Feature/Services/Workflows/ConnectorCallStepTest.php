<?php

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Models\App;
use App\Models\Integration;
use App\Models\Record;
use App\Models\Tool;
use App\Models\User;
use App\Services\Security\Ssrf\DnsResolver;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function cc_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function cc_manifest(string $appId, array $workflow): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'cc_test',
        'name' => 'CC Test',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'workflows' => [$workflow],
        'permissions' => ['roles' => [['id' => cc_id('rol'), 'slug' => 'admin', 'name' => 'A']]],
    ];
}

function cc_workflow(string $toolId, array $inputs = []): array
{
    return [
        'id' => cc_id('wkf'), 'slug' => 'call', 'name' => 'Call',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => cc_id('stp'),
            'type' => 'connector.call',
            'tool_id' => $toolId,
            'inputs' => $inputs,
            'output_variable' => 'result',
        ]],
    ];
}

beforeEach(function () {
    // connector.call flows through the SSRF guard; fake DNS to a public IP so
    // faked endpoints pass the guard. Bind before the engine is built.
    app()->bind(DnsResolver::class, fn () => new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });

    $this->engine = app(WorkflowEngine::class);
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
    ]);
});

it('executes a connector.call against a configured rest tool', function () {
    Http::fake([
        'api.example.com/*' => Http::response(['name' => 'Acme', 'amount' => 42000], 200),
    ]);

    $tool = Tool::factory()->create([
        'type' => ToolType::RestApi,
        'status' => AgentStatus::Active,
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'config' => [
            'base_url' => 'https://api.example.com',
            'method' => 'GET',
            'path' => '/deals/{{deal_id}}',
            'auth_type' => 'none',
        ],
    ]);

    $workflow = cc_workflow($tool->id, ['deal_id' => '{{trigger.id}}']);
    $manifest = cc_manifest($this->testApp->id, $workflow);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', ['id' => 'D-1'], $this->user);

    expect($run->status)->toBe('completed');

    $step = $run->steps()->first();
    expect($step->status)->toBe('completed');
    expect($step->output['effect'])->toBe('read');
    expect($step->output['data'])->toMatchArray(['name' => 'Acme', 'amount' => 42000]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/deals/D-1'));
});

it('blocks a connector.call whose host resolves to an internal address (SSRF)', function () {
    // Rebind DNS to loopback and rebuild the engine so its guard sees it.
    app()->bind(DnsResolver::class, fn () => new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['127.0.0.1'];
        }
    });
    $engine = app(WorkflowEngine::class);

    $tool = Tool::factory()->create([
        'type' => ToolType::RestApi,
        'status' => AgentStatus::Active,
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'config' => [
            'base_url' => 'https://metadata.internal.example',
            'method' => 'GET',
            'path' => '/secrets',
            'auth_type' => 'none',
        ],
    ]);

    $workflow = cc_workflow($tool->id);
    $manifest = cc_manifest($this->testApp->id, $workflow);

    $run = $engine->run($this->testApp, $manifest, $workflow, 'manual', [], $this->user);

    expect($run->status)->toBe('failed');
    expect($run->error)->toContain('Blocked destination');
});

it('dry-run simulates a connector write without calling the external system', function () {
    Http::fake(); // any real call would be recorded; we assert none happens.

    $tool = Tool::factory()->create([
        'type' => ToolType::RestApi,
        'status' => AgentStatus::Active,
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'config' => [
            'base_url' => 'https://api.example.com',
            'method' => 'POST',
            'path' => '/messages',
            'auth_type' => 'none',
        ],
    ]);

    $workflow = cc_workflow($tool->id, ['text' => 'hi']);
    $manifest = cc_manifest($this->testApp->id, $workflow);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', [], $this->user, dryRun: true);

    expect($run->status)->toBe('completed');
    expect($run->dry_run)->toBeTrue();

    $step = $run->steps()->first();
    expect($step->status)->toBe('completed');
    expect($step->output['simulated'])->toBeTrue();
    expect($step->output['effect'])->toBe('write');
    expect($step->output['proposal']['preview'])->toContain('example.com');

    Http::assertNothingSent();
});

it('dry-run simulates a record.create without writing a real record', function () {
    $object = ['id' => cc_id('obj'), 'slug' => 'tareas', 'name' => 'Tarea', 'fields' => [
        ['id' => cc_id('fld'), 'slug' => 'titulo', 'name' => 'Título', 'type' => 'string'],
    ]];
    $workflow = [
        'id' => cc_id('wkf'), 'slug' => 'c', 'name' => 'C',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => cc_id('stp'), 'type' => 'record.create',
            'object_id' => $object['id'], 'values' => ['titulo' => 'Test'],
        ]],
    ];
    $manifest = cc_manifest($this->testApp->id, $workflow);
    $manifest['objects'] = [$object];

    $before = Record::query()->where('app_id', $this->testApp->id)->count();

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', [], $this->user, dryRun: true);

    expect($run->status)->toBe('completed');
    expect($run->steps()->first()->output['simulated'])->toBeTrue();
    expect(Record::query()->where('app_id', $this->testApp->id)->count())->toBe($before);
});

it('fails with a clear error when the connector tool is unknown', function () {
    $workflow = cc_workflow('tool_does_not_exist');
    $manifest = cc_manifest($this->testApp->id, $workflow);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', [], $this->user);

    expect($run->status)->toBe('failed');
    expect($run->error)->toContain('unknown or inaccessible tool');
});

it('refuses an unauthorized per-user oauth connector with an authorize error', function () {
    $integration = Integration::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
    ]);

    $tool = Tool::factory()->create([
        'type' => ToolType::RestApi,
        'status' => AgentStatus::Active,
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'config' => [
            'base_url' => 'https://api.example.com',
            'method' => 'GET',
            'path' => '/me',
            'auth_type' => 'oauth2',
            'integration_id' => $integration->id,
        ],
    ]);

    $workflow = cc_workflow($tool->id);
    $manifest = cc_manifest($this->testApp->id, $workflow);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', [], $this->user);

    expect($run->status)->toBe('failed');
    expect($run->error)->toContain('needs authorization');
});
