<?php

use App\Jobs\RunDateReachedWorkflowJob;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function drid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

/**
 * Manifest with a datetime field + status select and one record.date_reached
 * workflow firing `1 day before` the date. Returns [manifest, objId, dueField].
 */
function dateReachedManifest(string $appId, ?array $filter = null): array
{
    $objId = drid('obj');
    $fldDue = drid('fld');
    $fldStatus = drid('fld');

    $trigger = [
        'type' => 'record.date_reached',
        'object_id' => $objId,
        'field_id' => $fldDue,
        'offset' => ['value' => 1, 'unit' => 'days', 'direction' => 'before'],
    ];
    if ($filter !== null) {
        $trigger['filter'] = $filter;
    }

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'dr_'.strtolower(Str::random(6)),
        'name' => 'Date Reached App',
        'version' => 1,
        'objects' => [[
            'id' => $objId,
            'slug' => 'invoices',
            'name' => 'Invoice',
            'fields' => [
                ['id' => $fldDue, 'slug' => 'due_at', 'name' => 'Due at', 'type' => 'datetime'],
                ['id' => $fldStatus, 'slug' => 'status', 'name' => 'Status', 'type' => 'single_select', 'options' => [
                    ['id' => drid('opt'), 'value' => 'open', 'label' => 'Open'],
                    ['id' => drid('opt'), 'value' => 'paid', 'label' => 'Paid'],
                ]],
            ],
        ]],
        'pages' => [],
        'workflows' => [[
            'id' => drid('wkf'),
            'slug' => 'remind',
            'name' => 'Reminder',
            'trigger' => $trigger,
            'steps' => [['id' => drid('stp'), 'type' => 'log', 'message' => 'due soon']],
        ]],
        'permissions' => ['roles' => [['id' => drid('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];

    return [$manifest, $objId, $fldStatus];
}

function makeInvoice(App $app, string $objId, string $dueAtIso, array $extra = []): Record
{
    return Record::create([
        'organization_id' => $app->organization_id,
        'app_id' => $app->id,
        'object_definition_id' => $objId,
        'data' => array_merge(['due_at' => $dueAtIso], $extra),
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('seeds the cursor on first sweep and fires nothing (no backfill)', function () {
    Carbon::setTestNow('2026-06-30T12:00:00Z');
    [$manifest] = dateReachedManifest($this->testApp->id);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    // Due tomorrow → target (1 day before) is exactly now; still a first sweep.
    makeInvoice($this->testApp, $manifest['objects'][0]['id'], '2026-07-01T12:05:00Z');

    Queue::fake();
    $this->artisan('flows:dispatch-date-reached')->assertSuccessful();

    Queue::assertNotPushed(RunDateReachedWorkflowJob::class);
});

it('fires once when the target moment enters the window, and never again', function () {
    Carbon::setTestNow('2026-06-30T12:00:00Z');
    [$manifest] = dateReachedManifest($this->testApp->id);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    // Due tomorrow 12:05 → target = today 12:05 (1 day before).
    $invoice = makeInvoice($this->testApp, $manifest['objects'][0]['id'], '2026-07-01T12:05:00Z');

    Queue::fake();

    // First sweep seeds the cursor at 12:00 → target 12:05 not yet due.
    $this->artisan('flows:dispatch-date-reached')->assertSuccessful();
    Queue::assertNotPushed(RunDateReachedWorkflowJob::class);

    // 10 minutes later the target (12:05) falls in (12:00, 12:10].
    Carbon::setTestNow('2026-06-30T12:10:00Z');
    $this->artisan('flows:dispatch-date-reached')->assertSuccessful();
    Queue::assertPushed(
        RunDateReachedWorkflowJob::class,
        fn (RunDateReachedWorkflowJob $job): bool => $job->record['id'] === $invoice->id,
    );

    // A further sweep at the same instant must not re-fire (cursor advanced).
    $this->artisan('flows:dispatch-date-reached')->assertSuccessful();
    Queue::assertPushed(RunDateReachedWorkflowJob::class, 1);
});

it('only fires for records that also match the trigger filter', function () {
    Carbon::setTestNow('2026-06-30T12:00:00Z');
    [$manifest, , $fldStatus] = dateReachedManifest($this->testApp->id, [
        'op' => 'eq', 'field_id' => null, 'value' => 'open',
    ]);
    // Point the filter at the status field id we just generated.
    $manifest['workflows'][0]['trigger']['filter']['field_id'] = $fldStatus;
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    $objId = $manifest['objects'][0]['id'];
    $open = makeInvoice($this->testApp, $objId, '2026-07-01T12:05:00Z', ['status' => 'open']);
    makeInvoice($this->testApp, $objId, '2026-07-01T12:05:00Z', ['status' => 'paid']);

    Queue::fake();
    $this->artisan('flows:dispatch-date-reached')->assertSuccessful(); // seed
    Carbon::setTestNow('2026-06-30T12:10:00Z');
    $this->artisan('flows:dispatch-date-reached')->assertSuccessful();

    Queue::assertPushed(RunDateReachedWorkflowJob::class, 1);
    Queue::assertPushed(
        RunDateReachedWorkflowJob::class,
        fn (RunDateReachedWorkflowJob $job): bool => $job->record['id'] === $open->id,
    );
});
