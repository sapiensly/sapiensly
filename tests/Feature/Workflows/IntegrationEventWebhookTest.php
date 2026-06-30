<?php

use App\Jobs\DispatchIntegrationEventWorkflows;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function webhookIntegration(array $auth): Integration
{
    $user = User::factory()->create();

    return Integration::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'auth_type' => 'none',
        'status' => 'active',
        'name' => 'GitHub',
        'auth_config' => $auth,
    ]);
}

function postWebhook(Integration $integration, array $body, ?string $signature): TestResponse
{
    $raw = json_encode($body);
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($signature !== null) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
    }

    return test()->call(
        'POST',
        route('webhooks.integrations.receive', $integration),
        [],
        [],
        [],
        $server,
        $raw,
    );
}

it('accepts a correctly signed delivery and enqueues the fan-out', function () {
    Queue::fake();
    $secret = 'gh-secret';
    $integration = webhookIntegration(['webhook_secret' => $secret]);
    $body = ['action' => 'opened', 'type' => 'pull_request'];
    $sig = 'sha256='.hash_hmac('sha256', json_encode($body), $secret);

    postWebhook($integration, $body, $sig)->assertStatus(202);

    Queue::assertPushed(
        DispatchIntegrationEventWorkflows::class,
        fn (DispatchIntegrationEventWorkflows $job): bool => $job->integrationId === $integration->id
            && $job->payload['event'] === 'pull_request',
    );
});

it('rejects a delivery with a bad signature', function () {
    Queue::fake();
    $integration = webhookIntegration(['webhook_secret' => 'gh-secret']);

    postWebhook($integration, ['x' => 1], 'sha256=deadbeef')->assertStatus(401);

    Queue::assertNotPushed(DispatchIntegrationEventWorkflows::class);
});

it('404s when the integration has no inbound webhook configured', function () {
    Queue::fake();
    $integration = webhookIntegration([]); // no webhook_secret

    postWebhook($integration, ['x' => 1], 'sha256=whatever')->assertNotFound();

    Queue::assertNotPushed(DispatchIntegrationEventWorkflows::class);
});
