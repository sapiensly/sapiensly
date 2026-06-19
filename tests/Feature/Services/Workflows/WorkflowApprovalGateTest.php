<?php

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Models\App;
use App\Models\Tool;
use App\Models\User;
use App\Models\WorkflowProposal;
use App\Services\Security\Ssrf\DnsResolver;
use App\Services\Workflows\WorkflowEngine;
use App\Services\Workflows\WorkflowProposalService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function ag_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function ag_manifest(string $appId, array $workflow): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'ag_'.strtolower(Str::random(6)),
        'name' => 'Approval Gate App',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'workflows' => [$workflow],
        'permissions' => ['roles' => [['id' => ag_id('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

function ag_writeTool(User $user, bool $safe): Tool
{
    return Tool::factory()->create([
        'type' => ToolType::RestApi,
        'status' => AgentStatus::Active,
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'safe' => $safe,
        'config' => [
            'base_url' => 'https://api.example.com',
            'method' => 'POST',
            'path' => '/messages',
            'auth_type' => 'none',
        ],
    ]);
}

function ag_workflow(string $toolId): array
{
    return [
        'id' => ag_id('wkf'), 'slug' => 'call', 'name' => 'Call',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => ag_id('stp'),
            'type' => 'connector.call',
            'tool_id' => $toolId,
            'inputs' => ['text' => 'hi'],
            'output_variable' => 'result',
        ]],
    ];
}

beforeEach(function () {
    app()->bind(DnsResolver::class, fn () => new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });

    $this->engine = app(WorkflowEngine::class);
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'visibility' => 'private',
    ]);
});

it('halts a real run on a non-safe connector write and emits a pending proposal', function () {
    Http::fake();
    $tool = ag_writeTool($this->user, safe: false);
    $workflow = ag_workflow($tool->id);

    $run = $this->engine->run($this->testApp, ag_manifest($this->testApp->id, $workflow), $workflow, 'manual', [], $this->user);

    expect($run->status)->toBe('awaiting_approval');

    $proposal = WorkflowProposal::query()->where('run_id', $run->id)->first();
    expect($proposal)->not->toBeNull();
    expect($proposal->status)->toBe('pending');
    expect($proposal->effect)->toBe('write');
    expect($proposal->action['tool_id'])->toBe($tool->id);
    expect($proposal->action['inputs'])->toMatchArray(['text' => 'hi']);

    Http::assertNothingSent();
});

it('executes a safe connector write directly without a proposal', function () {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);
    $tool = ag_writeTool($this->user, safe: true);
    $workflow = ag_workflow($tool->id);

    $run = $this->engine->run($this->testApp, ag_manifest($this->testApp->id, $workflow), $workflow, 'manual', [], $this->user);

    expect($run->status)->toBe('completed');
    expect(WorkflowProposal::query()->where('run_id', $run->id)->count())->toBe(0);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages'));
});

it('executes the external write only when the proposal is approved', function () {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);
    $tool = ag_writeTool($this->user, safe: false);
    $workflow = ag_workflow($tool->id);

    $run = $this->engine->run($this->testApp, ag_manifest($this->testApp->id, $workflow), $workflow, 'manual', [], $this->user);
    Http::assertNothingSent(); // nothing yet — the gate held

    $proposal = WorkflowProposal::query()->where('run_id', $run->id)->firstOrFail();
    $outcome = app(WorkflowProposalService::class)->approve($proposal, $this->user);

    expect($outcome['ok'])->toBeTrue();
    expect($proposal->fresh()->status)->toBe('approved');
    expect($proposal->fresh()->resolved_by_user_id)->toBe($this->user->id);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages'));
});

it('dismiss discards a proposal and never calls out', function () {
    Http::fake();
    $tool = ag_writeTool($this->user, safe: false);
    $workflow = ag_workflow($tool->id);

    $run = $this->engine->run($this->testApp, ag_manifest($this->testApp->id, $workflow), $workflow, 'manual', [], $this->user);
    $proposal = WorkflowProposal::query()->where('run_id', $run->id)->firstOrFail();

    expect(app(WorkflowProposalService::class)->dismiss($proposal, $this->user))->toBeTrue();
    expect($proposal->fresh()->status)->toBe('dismissed');
    Http::assertNothingSent();
});

it('refuses to approve an already-resolved proposal', function () {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);
    $tool = ag_writeTool($this->user, safe: false);
    $workflow = ag_workflow($tool->id);

    $run = $this->engine->run($this->testApp, ag_manifest($this->testApp->id, $workflow), $workflow, 'manual', [], $this->user);
    $proposal = WorkflowProposal::query()->where('run_id', $run->id)->firstOrFail();

    $service = app(WorkflowProposalService::class);
    $service->approve($proposal, $this->user);
    $second = $service->approve($proposal->fresh(), $this->user);

    expect($second['ok'])->toBeFalse();
    expect($second['error'])->toContain('already been resolved');
});

it('exposes pending proposals and resolves them over HTTP, forbidding strangers', function () {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);
    $tool = ag_writeTool($this->user, safe: false);
    $workflow = ag_workflow($tool->id);

    $run = $this->engine->run($this->testApp, ag_manifest($this->testApp->id, $workflow), $workflow, 'manual', [], $this->user);
    $proposal = WorkflowProposal::query()->where('run_id', $run->id)->firstOrFail();

    $list = $this->actingAs($this->user)
        ->getJson("/apps/{$this->testApp->id}/builder/workflow-proposals")
        ->assertOk()
        ->json('proposals');
    expect($list)->toHaveCount(1);
    expect($list[0])->toMatchArray(['id' => $proposal->id, 'effect' => 'write']);

    $stranger = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($stranger)
        ->postJson("/apps/{$this->testApp->id}/builder/workflow-proposals/{$proposal->id}/approve")
        ->assertForbidden();

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/workflow-proposals/{$proposal->id}/approve")
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($proposal->fresh()->status)->toBe('approved');
});
