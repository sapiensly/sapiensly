<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\Records\RecordWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function triggerManifest(string $objId, string $fldStatus, ?array $filter): array
{
    $workflow = [
        'id' => 'wkf_'.strtolower((string) Str::ulid()),
        'slug' => 'on_event',
        'name' => 'On event',
        'enabled' => true,
        'trigger' => array_filter([
            'type' => 'record.created',
            'object_id' => $objId,
            'filter' => $filter,
        ], fn ($v) => $v !== null),
        'steps' => [[
            'id' => 'stp_'.strtolower((string) Str::ulid()),
            'type' => 'log',
            'message' => 'fired',
        ]],
    ];

    return [
        'objects' => [[
            'id' => $objId,
            'slug' => 'tickets',
            'name' => 'Ticket',
            'fields' => [[
                'id' => $fldStatus,
                'slug' => 'status',
                'name' => 'Status',
                'type' => 'single_select',
                'options' => [
                    ['value' => 'open', 'label' => 'Open'],
                    ['value' => 'closed', 'label' => 'Closed'],
                ],
            ]],
        ]],
        'workflows' => [$workflow],
    ];
}

it('fires a record.created workflow only when its filter matches', function () {
    $user = User::factory()->create();
    $app = App::factory()->create();
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $fldStatus = 'fld_'.strtolower((string) Str::ulid());
    $manifest = triggerManifest($objId, $fldStatus, ['op' => 'eq', 'field_id' => $fldStatus, 'value' => 'open']);

    $svc = app(RecordWriteService::class);

    $svc->create($app, $manifest, $objId, ['status' => 'open'], $user);
    expect(WorkflowRun::count())->toBe(1); // filter matched → fired

    $svc->create($app, $manifest, $objId, ['status' => 'closed'], $user);
    expect(WorkflowRun::count())->toBe(1); // filter did not match → no new run
});

it('still fires every write when the trigger has no filter', function () {
    $user = User::factory()->create();
    $app = App::factory()->create();
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $fldStatus = 'fld_'.strtolower((string) Str::ulid());
    $manifest = triggerManifest($objId, $fldStatus, null);

    $svc = app(RecordWriteService::class);
    $svc->create($app, $manifest, $objId, ['status' => 'open'], $user);
    $svc->create($app, $manifest, $objId, ['status' => 'closed'], $user);

    expect(WorkflowRun::count())->toBe(2);
});

it('evaluates the filter on update against the new record state', function () {
    $user = User::factory()->create();
    $app = App::factory()->create();
    $objId = 'obj_'.strtolower((string) Str::ulid());
    $fldStatus = 'fld_'.strtolower((string) Str::ulid());

    // record.updated workflow filtered on status = open
    $manifest = triggerManifest($objId, $fldStatus, ['op' => 'eq', 'field_id' => $fldStatus, 'value' => 'open']);
    $manifest['workflows'][0]['trigger']['type'] = 'record.updated';

    $record = Record::create([
        'organization_id' => $app->organization_id,
        'app_id' => $app->id,
        'object_definition_id' => $objId,
        'data' => ['status' => 'closed'],
    ]);

    $svc = app(RecordWriteService::class);

    $svc->update($app, $manifest, $record, ['status' => 'closed'], $user);
    expect(WorkflowRun::count())->toBe(0); // still closed → no fire

    $svc->update($app, $manifest, $record, ['status' => 'open'], $user);
    expect(WorkflowRun::count())->toBe(1); // now open → fires
});
