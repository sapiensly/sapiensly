<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\Manifest\ManifestValidator;
use App\Services\Runtime\AutonomyPolicy;
use App\Services\Runtime\ProposedActions;
use App\Services\Runtime\RuntimeAgentService;
use Illuminate\Support\Facades\Http;

/**
 * Builder power #3 — the autonomy engine for the `safe` mark. A safe-marked
 * internal create/update — or an explicitly safe-marked workflow — auto-executes;
 * everything else stays gated. Safeguards: delete never auto, connected always
 * gated, a failed auto-run falls back to gated, every auto-run recorded
 * (auto_previews). See docs/app-builder-runtime-agent-contract.md §5.
 */
function au_object(string $id, array $extra = [], bool $required = false): array
{
    return [
        'id' => $id,
        'slug' => 'tasks',
        'name' => 'Task',
        'fields' => [['id' => 'fld_titlefield', 'slug' => 'title', 'name' => 'Title', 'type' => 'string', 'required' => $required]],
        ...$extra,
    ];
}

function au_manifest(string $autonomy, array $safe, array $objects): array
{
    return [
        'objects' => $objects,
        'agent' => [
            'enabled' => true,
            'capabilities' => ['read' => 'all', 'write' => 'all'],
            'autonomy' => $autonomy,
            'safe' => $safe,
        ],
    ];
}

function au_create(string $objectId, array $values = ['title' => 'X']): array
{
    return ['type' => 'create_record', 'object_id' => $objectId, 'values' => $values];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->autoApp = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => null]);
});

// --- AutonomyPolicy (the decision) ---

it('only auto-executes a safe-marked internal create/update', function () {
    $policy = app(AutonomyPolicy::class);
    $manifest = au_manifest('safe', [['object_id' => 'obj_task', 'actions' => ['create']]], [au_object('obj_task')]);

    expect($policy->isAutoExecutable($manifest, au_create('obj_task')))->toBeTrue()
        // update not in the safe actions → gated
        ->and($policy->isAutoExecutable($manifest, ['type' => 'update_record', 'object_id' => 'obj_task', 'record_id_expression' => 'r1', 'values' => []]))->toBeFalse();
});

it('never auto-executes when the master switch is not safe', function () {
    $manifest = au_manifest('propose', [['object_id' => 'obj_task', 'actions' => ['create']]], [au_object('obj_task')]);
    expect(app(AutonomyPolicy::class)->isAutoExecutable($manifest, au_create('obj_task')))->toBeFalse();
});

it('never auto-executes delete or run_workflow, even safe', function () {
    $policy = app(AutonomyPolicy::class);
    $manifest = au_manifest('safe', [['object_id' => 'obj_task', 'actions' => ['create']]], [au_object('obj_task')]);

    expect($policy->isAutoExecutable($manifest, ['type' => 'delete_record', 'object_id' => 'obj_task', 'record_id_expression' => 'r1']))->toBeFalse()
        ->and($policy->isAutoExecutable($manifest, ['type' => 'run_workflow', 'workflow_id' => 'wkf_x']))->toBeFalse();
});

it('never auto-executes a connected object (always gated)', function () {
    $manifest = au_manifest('safe', [['object_id' => 'obj_task', 'actions' => ['create']]], [
        au_object('obj_task', ['source' => ['type' => 'connected', 'integration_id' => 'integ_x']]),
    ]);
    expect(app(AutonomyPolicy::class)->isAutoExecutable($manifest, au_create('obj_task')))->toBeFalse();
});

it('auto-executes run_workflow only when that workflow is explicitly safe', function () {
    $policy = app(AutonomyPolicy::class);
    $manifest = au_manifest('safe', [['workflow_id' => 'wkf_auto']], [au_object('obj_task')]);

    expect($policy->isAutoExecutable($manifest, ['type' => 'run_workflow', 'workflow_id' => 'wkf_auto']))->toBeTrue()
        // a different workflow is not granted → gated
        ->and($policy->isAutoExecutable($manifest, ['type' => 'run_workflow', 'workflow_id' => 'wkf_other']))->toBeFalse();

    // propose mode → never, even if listed
    $proposeManifest = au_manifest('propose', [['workflow_id' => 'wkf_auto']], [au_object('obj_task')]);
    expect($policy->isAutoExecutable($proposeManifest, ['type' => 'run_workflow', 'workflow_id' => 'wkf_auto']))->toBeFalse();
});

it('accepts object and workflow safe entries in the manifest schema', function () {
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_autonomyschema',
        'slug' => 'a',
        'name' => 'A',
        'version' => 1,
        'objects' => [au_object('obj_taskobject')],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_adminrole', 'slug' => 'admin', 'name' => 'Admin']]],
        'agent' => [
            'enabled' => true,
            'capabilities' => ['read' => 'all', 'write' => 'all'],
            'autonomy' => 'safe',
            'safe' => [
                ['object_id' => 'obj_taskobject', 'actions' => ['create', 'update']],
                ['workflow_id' => 'wkf_autoflow'],
            ],
        ],
    ];

    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

// --- finalizeProposals (the engine) ---

it('auto-executes a safe internal create and records it visibly', function () {
    $manifest = au_manifest('safe', [['object_id' => 'obj_task', 'actions' => ['create']]], [au_object('obj_task')]);
    $proposals = new ProposedActions;
    $proposals->add(au_create('obj_task', ['title' => 'Auto']), 'Create Task: Title = Auto');

    $outcome = app(RuntimeAgentService::class)->finalizeProposals($this->autoApp, $manifest, $proposals, $this->user);

    expect($outcome['message_type'])->toBe('action_result')
        ->and($outcome['action_payload']['status'])->toBe('executed')
        ->and($outcome['action_payload']['auto_previews'])->toBe(['Create Task: Title = Auto']);

    expect(Record::query()->where('app_id', $this->autoApp->id)->count())->toBe(1);
});

it('keeps non-safe actions gated in a mixed turn', function () {
    $manifest = au_manifest('safe', [['object_id' => 'obj_task', 'actions' => ['create']]], [au_object('obj_task')]);
    $proposals = new ProposedActions;
    $proposals->add(au_create('obj_task', ['title' => 'Auto']), 'Create Task: Title = Auto');
    $proposals->add(['type' => 'delete_record', 'object_id' => 'obj_task', 'record_id_expression' => 'r1'], 'Delete Task (r1)');

    $outcome = app(RuntimeAgentService::class)->finalizeProposals($this->autoApp, $manifest, $proposals, $this->user);

    // The create applied automatically; the delete waits for approval.
    expect($outcome['message_type'])->toBe('action_proposal')
        ->and($outcome['action_payload']['status'])->toBe('pending')
        ->and($outcome['action_payload']['auto_previews'])->toBe(['Create Task: Title = Auto'])
        ->and($outcome['action_payload']['previews'])->toBe(['Delete Task (r1)'])
        ->and($outcome['action_payload']['actions'][0]['type'])->toBe('delete_record');

    expect(Record::query()->where('app_id', $this->autoApp->id)->count())->toBe(1);
});

it('falls back to gated when an auto-execution fails', function () {
    // title is required → an empty value fails validation inside the write path.
    $manifest = au_manifest('safe', [['object_id' => 'obj_task', 'actions' => ['create']]], [au_object('obj_task', required: true)]);
    $proposals = new ProposedActions;
    $proposals->add(au_create('obj_task', ['title' => '']), 'Create Task: Title = (empty)');

    $outcome = app(RuntimeAgentService::class)->finalizeProposals($this->autoApp, $manifest, $proposals, $this->user);

    expect($outcome['message_type'])->toBe('action_proposal')
        ->and($outcome['action_payload']['status'])->toBe('pending')
        ->and($outcome['action_payload'])->not->toHaveKey('auto_previews');

    expect(Record::query()->where('app_id', $this->autoApp->id)->count())->toBe(0);
});

it('leaves everything gated in propose mode', function () {
    $manifest = au_manifest('propose', [['object_id' => 'obj_task', 'actions' => ['create']]], [au_object('obj_task')]);
    $proposals = new ProposedActions;
    $proposals->add(au_create('obj_task'), 'Create Task');

    $outcome = app(RuntimeAgentService::class)->finalizeProposals($this->autoApp, $manifest, $proposals, $this->user);

    expect($outcome['message_type'])->toBe('action_proposal')
        ->and($outcome['action_payload']['status'])->toBe('pending');
    expect(Record::query()->where('app_id', $this->autoApp->id)->count())->toBe(0);
});

it('auto-runs a safe-marked workflow', function () {
    $workflow = [
        'id' => 'wkf_auto',
        'slug' => 'auto_wf',
        'name' => 'Auto WF',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => 'stp_create', 'type' => 'record.create', 'object_id' => 'obj_task', 'values' => ['title' => 'from-wf']]],
    ];
    $manifest = au_manifest('safe', [['workflow_id' => 'wkf_auto']], [au_object('obj_task')]);
    $manifest['workflows'] = [$workflow];

    $proposals = new ProposedActions;
    $proposals->add(['type' => 'run_workflow', 'workflow_id' => 'wkf_auto', 'input' => []], 'Run workflow Auto WF');

    $outcome = app(RuntimeAgentService::class)->finalizeProposals($this->autoApp, $manifest, $proposals, $this->user);

    expect($outcome['message_type'])->toBe('action_result')
        ->and($outcome['action_payload']['status'])->toBe('executed');
    expect(WorkflowRun::query()->where('app_id', $this->autoApp->id)->where('status', '!=', 'failed')->count())->toBe(1);
});

it('does not auto-execute a connected write even when safe', function () {
    Http::fake();
    $manifest = au_manifest('safe', [['object_id' => 'obj_task', 'actions' => ['create']]], [
        au_object('obj_task', ['source' => ['type' => 'connected', 'integration_id' => 'integ_x']]),
    ]);
    $proposals = new ProposedActions;
    $proposals->add(au_create('obj_task'), 'Create Task');

    $outcome = app(RuntimeAgentService::class)->finalizeProposals($this->autoApp, $manifest, $proposals, $this->user);

    expect($outcome['message_type'])->toBe('action_proposal')
        ->and($outcome['action_payload']['status'])->toBe('pending');
    Http::assertNothingSent();
});
