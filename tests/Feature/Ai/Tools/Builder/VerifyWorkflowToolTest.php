<?php

use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Ai\Tools\Builder\VerifyWorkflowTool;
use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Models\App;
use App\Models\Tool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use App\Services\Security\Ssrf\DnsResolver;
use App\Services\Workflows\WorkflowAssertionEvaluator;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

function vw_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function vw_manifest(string $appId, array $workflow): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'vw_'.strtolower(Str::random(6)),
        'name' => 'Verify Test App',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'workflows' => [$workflow],
        'permissions' => ['roles' => [['id' => vw_id('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

function vw_logWorkflow(string $id): array
{
    return [
        'id' => $id, 'slug' => 'log_flow', 'name' => 'Log',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => vw_id('stp'), 'type' => 'log', 'message' => 'hello', 'level' => 'info']],
    ];
}

function vw_tool(App $app, User $user): VerifyWorkflowTool
{
    // createVersion sets current_version_id on a freshly-locked row; refresh so
    // this in-memory instance can resolve the active manifest.
    $app->refresh();
    $proposeTool = new ProposeChangeTool($app, app(AppManifestService::class), app(ManifestValidator::class));

    return new VerifyWorkflowTool(
        $app,
        app(WorkflowEngine::class),
        app(WorkflowAssertionEvaluator::class),
        $proposeTool,
        $user,
    );
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'visibility' => 'private',
    ]);
});

it('passes a healthy workflow on the first attempt', function () {
    $wfId = vw_id('wkf');
    $workflow = vw_logWorkflow($wfId);
    app(AppManifestService::class)->createVersion($this->testApp, vw_manifest($this->testApp->id, $workflow), $this->user);

    $report = json_decode(vw_tool($this->testApp, $this->user)->handle(new ToolRequest([
        'workflow_id' => $wfId,
    ])), true);

    expect($report['passed'])->toBeTrue();
    expect($report['attempt'])->toBe(1);
    expect($report['stop_repairing'])->toBeFalse();
    expect($report['run']['status'])->toBe('completed');
    expect(collect($report['assertions'])->every(fn ($a) => $a['passed']))->toBeTrue();
});

it('counts attempts and stops after the max when the failure keeps changing', function () {
    $wfId = vw_id('wkf');
    app(AppManifestService::class)->createVersion($this->testApp, vw_manifest($this->testApp->id, vw_logWorkflow($wfId)), $this->user);

    $tool = vw_tool($this->testApp, $this->user);

    // Distinct failing assertions each call → distinct failure signatures, so the
    // oscillation guard never fires and only the attempt cap can stop the loop.
    $verify = fn (string $missingStep) => json_decode($tool->handle(new ToolRequest([
        'workflow_id' => $wfId,
        'assertions' => [['type' => 'step_status', 'step' => $missingStep, 'status' => 'completed']],
    ])), true);

    $first = $verify('stp_missing_a');
    expect($first['passed'])->toBeFalse();
    expect($first['attempt'])->toBe(1);
    expect($first['stop_repairing'])->toBeFalse();

    $second = $verify('stp_missing_b');
    expect($second['attempt'])->toBe(2);
    expect($second['stop_repairing'])->toBeFalse();

    $third = $verify('stp_missing_c');
    expect($third['attempt'])->toBe(3);
    expect($third['stop_repairing'])->toBeTrue();
    expect($third['stop_reason'])->toContain('limit');
});

it('stops early when the same failure recurs (oscillation guard)', function () {
    $wfId = vw_id('wkf');
    app(AppManifestService::class)->createVersion($this->testApp, vw_manifest($this->testApp->id, vw_logWorkflow($wfId)), $this->user);

    $tool = vw_tool($this->testApp, $this->user);
    $sameFailure = new ToolRequest([
        'workflow_id' => $wfId,
        'assertions' => [['type' => 'step_status', 'step' => 'stp_never', 'status' => 'completed']],
    ]);

    $first = json_decode($tool->handle($sameFailure), true);
    expect($first['stop_repairing'])->toBeFalse();

    $second = json_decode($tool->handle($sameFailure), true);
    expect($second['attempt'])->toBe(2);
    expect($second['stop_repairing'])->toBeTrue();
    expect($second['stop_reason'])->toContain('recurred');
});

it('surfaces simulated writes from a dry-run connector step without calling out', function () {
    app()->bind(DnsResolver::class, fn () => new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    Http::fake();

    $connector = Tool::factory()->create([
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

    $wfId = vw_id('wkf');
    $workflow = [
        'id' => $wfId, 'slug' => 'call', 'name' => 'Call',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => vw_id('stp'),
            'type' => 'connector.call',
            'tool_id' => $connector->id,
            'inputs' => ['text' => 'hi'],
            'output_variable' => 'result',
        ]],
    ];
    app(AppManifestService::class)->createVersion($this->testApp, vw_manifest($this->testApp->id, $workflow), $this->user);

    $report = json_decode(vw_tool($this->testApp, $this->user)->handle(new ToolRequest([
        'workflow_id' => $wfId,
    ])), true);

    expect($report['passed'])->toBeTrue();
    expect($report['simulated_writes'])->toHaveCount(1);
    expect($report['simulated_writes'][0]['effect'])->toBe('write');
    expect($report['simulated_writes'][0]['preview'])->toContain('example.com');

    Http::assertNothingSent();
});

it('returns an error for an unknown workflow id', function () {
    app(AppManifestService::class)->createVersion($this->testApp, vw_manifest($this->testApp->id, vw_logWorkflow(vw_id('wkf'))), $this->user);

    $report = json_decode(vw_tool($this->testApp, $this->user)->handle(new ToolRequest([
        'workflow_id' => 'wkf_does_not_exist',
    ])), true);

    expect($report['passed'])->toBeFalse();
    expect($report['error'])->toContain('not in the current manifest');
});
