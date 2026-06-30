<?php

use App\Jobs\DispatchEmailInboundWorkflows;
use App\Models\App;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\Manifest\AppManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function emid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function emailManifest(string $appId, string $integrationId, array $triggerExtra = []): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'em_'.strtolower(Str::random(6)),
        'name' => 'Email App',
        'version' => 1,
        'objects' => [[
            'id' => emid('obj'),
            'slug' => 'tickets',
            'name' => 'Ticket',
            'fields' => [['id' => emid('fld'), 'slug' => 'subject', 'name' => 'Subject', 'type' => 'string']],
        ]],
        'pages' => [],
        'workflows' => [[
            'id' => emid('wkf'),
            'slug' => 'on_email',
            'name' => 'On email',
            'trigger' => array_merge(['type' => 'email.inbound', 'integration_id' => $integrationId], $triggerExtra),
            'steps' => [['id' => emid('stp'), 'type' => 'log', 'message' => 'email {{trigger.email.subject}}']],
        ]],
        'permissions' => ['roles' => [['id' => emid('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

function emailPayload(string $integrationId, array $email, string $deliveryKey): array
{
    return [
        'integration_id' => $integrationId,
        'email' => array_merge(['from' => 'a@x.com', 'to' => 'support@us.com', 'subject' => 'Hi', 'text' => 'x'], $email),
        'delivery_key' => $deliveryKey,
    ];
}

function runEmailJob(App $app, string $integrationId, array $payload): void
{
    DispatchEmailInboundWorkflows::dispatchSync($integrationId, $app->organization_id, $app->user_id, $payload);
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->integrationId = emid('integ');
});

it('runs an email.inbound workflow bound to the integration', function () {
    $manifest = emailManifest($this->testApp->id, $this->integrationId);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    runEmailJob($this->testApp, $this->integrationId, emailPayload($this->integrationId, [], emid('del')));

    expect(WorkflowRun::where('trigger_type', 'email.inbound')->count())->toBe(1);
});

it('honours the to/subject filters', function () {
    $manifest = emailManifest($this->testApp->id, $this->integrationId, [
        'subject_contains' => 'refund',
    ]);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    runEmailJob($this->testApp, $this->integrationId, emailPayload($this->integrationId, ['subject' => 'just saying hi'], emid('del')));
    expect(WorkflowRun::count())->toBe(0);

    runEmailJob($this->testApp, $this->integrationId, emailPayload($this->integrationId, ['subject' => 'Please process my REFUND'], emid('del')));
    expect(WorkflowRun::count())->toBe(1);
});

it('dedupes repeated deliveries by message id', function () {
    $manifest = emailManifest($this->testApp->id, $this->integrationId);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    $key = emid('del');
    runEmailJob($this->testApp, $this->integrationId, emailPayload($this->integrationId, [], $key));
    runEmailJob($this->testApp, $this->integrationId, emailPayload($this->integrationId, [], $key));

    expect(WorkflowRun::count())->toBe(1);
});
