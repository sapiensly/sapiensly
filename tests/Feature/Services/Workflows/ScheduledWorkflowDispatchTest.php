<?php

use App\Jobs\RunScheduledWorkflowJob;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function sw_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function sw_manifest(string $appId, array $workflow): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'sw_'.strtolower(Str::random(6)),
        'name' => 'Scheduled Test App',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'workflows' => [$workflow],
        'permissions' => ['roles' => [['id' => sw_id('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

function sw_scheduleWorkflow(string $cron): array
{
    return [
        'id' => sw_id('wkf'), 'slug' => 'cron_flow', 'name' => 'Cron',
        'trigger' => ['type' => 'schedule', 'cron' => $cron],
        'steps' => [['id' => sw_id('stp'), 'type' => 'log', 'message' => 'tick', 'level' => 'info']],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
    ]);
    // A Monday at 09:00 UTC.
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('dispatches a workflow whose cron is due this minute', function () {
    Queue::fake();
    $workflow = sw_scheduleWorkflow('0 9 * * 1-5'); // weekdays at 09:00
    app(AppManifestService::class)->createVersion($this->testApp, sw_manifest($this->testApp->id, $workflow), $this->user);

    $this->artisan('flows:dispatch-scheduled')->assertSuccessful();

    Queue::assertPushed(RunScheduledWorkflowJob::class, 1);
    Queue::assertPushed(RunScheduledWorkflowJob::class, fn ($job) => $job->workflowId === $workflow['id']
        && $job->organizationId === $this->testApp->organization_id);
});

it('does not dispatch a workflow whose cron is not due', function () {
    Queue::fake();
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        sw_manifest($this->testApp->id, sw_scheduleWorkflow('0 10 * * *')), // 10:00, not 09:00
        $this->user,
    );

    $this->artisan('flows:dispatch-scheduled')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('is idempotent per fire — a second sweep in the same minute dispatches nothing', function () {
    Queue::fake();
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        sw_manifest($this->testApp->id, sw_scheduleWorkflow('0 9 * * 1-5')),
        $this->user,
    );

    $this->artisan('flows:dispatch-scheduled')->assertSuccessful();
    $this->artisan('flows:dispatch-scheduled')->assertSuccessful();

    Queue::assertPushed(RunScheduledWorkflowJob::class, 1);
});

it('skips a disabled scheduled workflow', function () {
    Queue::fake();
    $workflow = sw_scheduleWorkflow('0 9 * * 1-5');
    $workflow['enabled'] = false;
    app(AppManifestService::class)->createVersion($this->testApp, sw_manifest($this->testApp->id, $workflow), $this->user);

    $this->artisan('flows:dispatch-scheduled')->assertSuccessful();

    Queue::assertNothingPushed();
});
