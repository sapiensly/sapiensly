<?php

use App\Jobs\DispatchIntegrationEventWorkflows;
use App\Models\App;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\Manifest\AppManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function ieid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function integrationEventManifest(string $appId, string $integrationId, ?string $event = null): array
{
    $trigger = ['type' => 'integration.event', 'integration_id' => $integrationId];
    if ($event !== null) {
        $trigger['event'] = $event;
    }

    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'ie_'.strtolower(Str::random(6)),
        'name' => 'Integration Event App',
        'version' => 1,
        'objects' => [[
            'id' => ieid('obj'),
            'slug' => 'logs',
            'name' => 'Log',
            'fields' => [['id' => ieid('fld'), 'slug' => 'note', 'name' => 'Note', 'type' => 'string']],
        ]],
        'pages' => [],
        'workflows' => [[
            'id' => ieid('wkf'),
            'slug' => 'on_event',
            'name' => 'On event',
            'trigger' => $trigger,
            'steps' => [['id' => ieid('stp'), 'type' => 'log', 'message' => 'event {{trigger.event}}']],
        ]],
        'permissions' => ['roles' => [['id' => ieid('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

function eventPayload(string $integrationId, string $event, string $deliveryKey): array
{
    return [
        'integration_id' => $integrationId,
        'event' => $event,
        'body' => ['hello' => 'world'],
        'headers' => [],
        'delivery_key' => $deliveryKey,
    ];
}

function runEventJob(App $app, string $integrationId, array $payload): void
{
    DispatchIntegrationEventWorkflows::dispatchSync(
        $integrationId,
        $app->organization_id,
        $app->user_id,
        $payload,
    );
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->integrationId = ieid('integ');
});

it('runs a workflow bound to the integration', function () {
    $manifest = integrationEventManifest($this->testApp->id, $this->integrationId);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    runEventJob($this->testApp, $this->integrationId, eventPayload($this->integrationId, 'push', ieid('del')));

    expect(WorkflowRun::where('trigger_type', 'integration.event')->count())->toBe(1);
});

it('honours the optional event filter', function () {
    $manifest = integrationEventManifest($this->testApp->id, $this->integrationId, event: 'pull_request');
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    runEventJob($this->testApp, $this->integrationId, eventPayload($this->integrationId, 'push', ieid('del')));
    expect(WorkflowRun::count())->toBe(0);

    runEventJob($this->testApp, $this->integrationId, eventPayload($this->integrationId, 'PULL_REQUEST', ieid('del')));
    expect(WorkflowRun::count())->toBe(1);
});

it('dedupes repeated deliveries by delivery_key', function () {
    $manifest = integrationEventManifest($this->testApp->id, $this->integrationId);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    $key = ieid('del');
    runEventJob($this->testApp, $this->integrationId, eventPayload($this->integrationId, 'push', $key));
    runEventJob($this->testApp, $this->integrationId, eventPayload($this->integrationId, 'push', $key));

    expect(WorkflowRun::count())->toBe(1);
});

it('does not run when the integration does not match', function () {
    $manifest = integrationEventManifest($this->testApp->id, $this->integrationId);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    runEventJob($this->testApp, ieid('integ'), eventPayload(ieid('integ'), 'push', ieid('del')));

    expect(WorkflowRun::count())->toBe(0);
});
