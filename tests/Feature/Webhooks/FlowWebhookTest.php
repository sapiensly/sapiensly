<?php

use App\Jobs\RunWebhookWorkflowJob;
use App\Models\App;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Manifest\AppManifestService;
use App\Services\Workflows\WorkflowWebhookSignature;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function fw_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function fw_manifest(string $appId, array $workflow): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'fw_'.strtolower(Str::random(6)),
        'name' => 'Webhook Test App',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'workflows' => [$workflow],
        'permissions' => ['roles' => [['id' => fw_id('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

function fw_webhookWorkflow(array $trigger = []): array
{
    return [
        'id' => fw_id('wkf'), 'slug' => 'hook_flow', 'name' => 'Hook',
        'trigger' => array_merge(['type' => 'webhook.inbound'], $trigger),
        'steps' => [['id' => fw_id('stp'), 'type' => 'log', 'message' => 'got it', 'level' => 'info']],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
    ]);
    $this->signer = app(WorkflowWebhookSignature::class);
});

function fw_signed(App $app, string $workflowId, array $body, WorkflowWebhookSignature $signer): array
{
    $raw = json_encode($body);
    $secret = $signer->secretFor($app->id, $workflowId);

    return ['X-Sapiensly-Signature' => 'sha256='.hash_hmac('sha256', $raw, $secret)];
}

it('accepts a correctly signed delivery and enqueues the run', function () {
    Queue::fake();
    $workflow = fw_webhookWorkflow();
    app(AppManifestService::class)->createVersion($this->testApp, fw_manifest($this->testApp->id, $workflow), $this->user);

    $body = ['id' => 'evt_1', 'amount' => 42];
    $headers = fw_signed($this->testApp, $workflow['id'], $body, $this->signer);

    $this->postJson("/webhooks/flows/{$this->testApp->id}/{$workflow['id']}", $body, $headers)
        ->assertStatus(202)
        ->assertJson(['status' => 'accepted']);

    Queue::assertPushed(RunWebhookWorkflowJob::class, 1);
    expect(WebhookDelivery::query()->where('workflow_id', $workflow['id'])->count())->toBe(1);
});

it('rejects a delivery with an invalid signature', function () {
    Queue::fake();
    $workflow = fw_webhookWorkflow();
    app(AppManifestService::class)->createVersion($this->testApp, fw_manifest($this->testApp->id, $workflow), $this->user);

    $this->postJson("/webhooks/flows/{$this->testApp->id}/{$workflow['id']}", ['id' => 'evt_1'], [
        'X-Sapiensly-Signature' => 'sha256=deadbeef',
    ])->assertStatus(401);

    Queue::assertNothingPushed();
});

it('dedupes a retried delivery — second identical post does not re-run', function () {
    Queue::fake();
    $workflow = fw_webhookWorkflow(['dedupe_path' => 'id']);
    app(AppManifestService::class)->createVersion($this->testApp, fw_manifest($this->testApp->id, $workflow), $this->user);

    $body = ['id' => 'evt_dup', 'amount' => 1];
    $headers = fw_signed($this->testApp, $workflow['id'], $body, $this->signer);
    $url = "/webhooks/flows/{$this->testApp->id}/{$workflow['id']}";

    $this->postJson($url, $body, $headers)->assertStatus(202);
    $this->postJson($url, $body, $headers)->assertStatus(200)->assertJson(['status' => 'duplicate']);

    Queue::assertPushed(RunWebhookWorkflowJob::class, 1);
    expect(WebhookDelivery::query()->where('workflow_id', $workflow['id'])->count())->toBe(1);
});

it('404s when the workflow is not an inbound webhook', function () {
    Queue::fake();
    $workflow = [
        'id' => fw_id('wkf'), 'slug' => 'manual_flow', 'name' => 'Manual',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => fw_id('stp'), 'type' => 'log', 'message' => 'x', 'level' => 'info']],
    ];
    app(AppManifestService::class)->createVersion($this->testApp, fw_manifest($this->testApp->id, $workflow), $this->user);

    $body = ['id' => 'evt_1'];
    $headers = fw_signed($this->testApp, $workflow['id'], $body, $this->signer);

    $this->postJson("/webhooks/flows/{$this->testApp->id}/{$workflow['id']}", $body, $headers)
        ->assertStatus(404);

    Queue::assertNothingPushed();
});
