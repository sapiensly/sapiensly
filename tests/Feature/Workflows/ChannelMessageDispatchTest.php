<?php

use App\Jobs\DispatchChannelMessageWorkflows;
use App\Models\App;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\Manifest\AppManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function cmid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function channelWorkflowManifest(string $appId, string $channelId, ?string $contains = null): array
{
    $trigger = ['type' => 'channel.message_received', 'channel_id' => $channelId];
    if ($contains !== null) {
        $trigger['contains'] = $contains;
    }

    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'cm_'.strtolower(Str::random(6)),
        'name' => 'Channel App',
        'version' => 1,
        'objects' => [[
            'id' => cmid('obj'),
            'slug' => 'logs',
            'name' => 'Log',
            'fields' => [['id' => cmid('fld'), 'slug' => 'note', 'name' => 'Note', 'type' => 'string']],
        ]],
        'pages' => [],
        'workflows' => [[
            'id' => cmid('wkf'),
            'slug' => 'on_message',
            'name' => 'On message',
            'trigger' => $trigger,
            'steps' => [['id' => cmid('stp'), 'type' => 'log', 'message' => 'got {{trigger.message.text}}']],
        ]],
        'permissions' => ['roles' => [['id' => cmid('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

function payloadFor(string $channelId, string $text): array
{
    return [
        'channel' => ['id' => $channelId, 'type' => 'whatsapp', 'name' => 'Support'],
        'message' => ['text' => $text, 'content_type' => 'text'],
        'contact' => ['id' => 'con_1', 'name' => 'Ana', 'identifier' => '+5215555'],
        'conversation_id' => 'wac_1',
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
    $this->channelId = cmid('chan');
});

function runChannelJob(App $app, string $channelId, array $payload): void
{
    DispatchChannelMessageWorkflows::dispatchSync(
        $channelId,
        $app->organization_id,
        $app->user_id,
        $payload,
    );
}

it('runs a workflow bound to the inbound channel', function () {
    $manifest = channelWorkflowManifest($this->testApp->id, $this->channelId);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    runChannelJob($this->testApp, $this->channelId, payloadFor($this->channelId, 'hello'));

    expect(WorkflowRun::where('trigger_type', 'channel.message_received')->count())->toBe(1);
});

it('does not run when the message is on a different channel', function () {
    $manifest = channelWorkflowManifest($this->testApp->id, $this->channelId);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    runChannelJob($this->testApp, cmid('chan'), payloadFor(cmid('chan'), 'hello'));

    expect(WorkflowRun::count())->toBe(0);
});

it('honours the optional contains keyword gate', function () {
    $manifest = channelWorkflowManifest($this->testApp->id, $this->channelId, contains: 'refund');
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    runChannelJob($this->testApp, $this->channelId, payloadFor($this->channelId, 'just saying hi'));
    expect(WorkflowRun::count())->toBe(0);

    runChannelJob($this->testApp, $this->channelId, payloadFor($this->channelId, 'I need a REFUND please'));
    expect(WorkflowRun::count())->toBe(1);
});

it('does not run workflows from a different owner', function () {
    $manifest = channelWorkflowManifest($this->testApp->id, $this->channelId);
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->user);

    // Same channel id, but dispatched for a different organization owner.
    DispatchChannelMessageWorkflows::dispatchSync(
        $this->channelId,
        'org_'.strtolower((string) Str::ulid()),
        null,
        payloadFor($this->channelId, 'hello'),
    );

    expect(WorkflowRun::count())->toBe(0);
});
