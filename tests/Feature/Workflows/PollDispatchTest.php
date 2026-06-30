<?php

use App\DTOs\ToolExecutionResult;
use App\Enums\AgentStatus;
use App\Jobs\RunIntegrationPollWorkflowJob;
use App\Models\App;
use App\Models\Tool;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\ToolExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pollid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function pollManifest(string $appId, string $toolId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'poll_'.strtolower(Str::random(6)),
        'name' => 'Poll App',
        'version' => 1,
        'objects' => [[
            'id' => pollid('obj'),
            'slug' => 'logs',
            'name' => 'Log',
            'fields' => [['id' => pollid('fld'), 'slug' => 'note', 'name' => 'Note', 'type' => 'string']],
        ]],
        'pages' => [],
        'workflows' => [[
            'id' => pollid('wkf'),
            'slug' => 'poll',
            'name' => 'Poll orders',
            'trigger' => [
                'type' => 'integration.poll',
                'tool_id' => $toolId,
                'items_path' => 'items',
                'watermark_path' => 'id',
                'interval_minutes' => 5,
            ],
            'steps' => [['id' => pollid('stp'), 'type' => 'log', 'message' => 'new {{trigger.item.id}}']],
        ]],
        'permissions' => ['roles' => [['id' => pollid('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->tool = Tool::factory()->restApi()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->testApp->organization_id,
        'status' => AgentStatus::Active,
        'config' => ['integration_id' => pollid('integ'), 'method' => 'GET'],
    ]);
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        pollManifest($this->testApp->id, $this->tool->id),
        $this->user,
    );
});

afterEach(fn () => Carbon::setTestNow());

it('seeds the watermark on the first poll and fires nothing, then fires only newer items', function () {
    Carbon::setTestNow('2026-06-30T12:00:00Z');

    $this->mock(ToolExecutionService::class, function ($mock) {
        $mock->shouldReceive('execute')->andReturn(
            ToolExecutionResult::success(['items' => [['id' => 1], ['id' => 2]]]),
            ToolExecutionResult::success(['items' => [['id' => 1], ['id' => 2], ['id' => 3]]]),
        );
    });

    Queue::fake();

    // First poll: seeds watermark = 2, fires nothing.
    $this->artisan('flows:dispatch-polls')->assertSuccessful();
    Queue::assertNotPushed(RunIntegrationPollWorkflowJob::class);

    // After the interval, only id 3 is newer than the watermark.
    Carbon::setTestNow('2026-06-30T12:06:00Z');
    $this->artisan('flows:dispatch-polls')->assertSuccessful();

    Queue::assertPushed(RunIntegrationPollWorkflowJob::class, 1);
    Queue::assertPushed(
        RunIntegrationPollWorkflowJob::class,
        fn (RunIntegrationPollWorkflowJob $job): bool => ($job->payload['item']['id'] ?? null) === 3,
    );
});

it('does not poll again before the interval elapses', function () {
    Carbon::setTestNow('2026-06-30T12:00:00Z');

    $this->mock(ToolExecutionService::class, function ($mock) {
        // Only the first (seed) poll should ever call execute.
        $mock->shouldReceive('execute')->once()->andReturn(
            ToolExecutionResult::success(['items' => [['id' => 1]]]),
        );
    });

    Queue::fake();

    $this->artisan('flows:dispatch-polls')->assertSuccessful(); // seeds
    // 2 minutes later — interval is 5, so this run is gated out (no execute).
    Carbon::setTestNow('2026-06-30T12:02:00Z');
    $this->artisan('flows:dispatch-polls')->assertSuccessful();

    Queue::assertNotPushed(RunIntegrationPollWorkflowJob::class);
});
