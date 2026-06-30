<?php

use App\Jobs\DispatchEmailInboundWorkflows;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function emailIntegration(array $auth): Integration
{
    $user = User::factory()->create();

    return Integration::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'auth_type' => 'none',
        'status' => 'active',
        'name' => 'Postmark inbound',
        'auth_config' => $auth,
    ]);
}

it('accepts an email via a matching token and enqueues the fan-out', function () {
    Queue::fake();
    $integration = emailIntegration(['webhook_secret' => 'tok-123', 'email_provider' => 'postmark']);

    test()->withHeaders(['X-Webhook-Token' => 'tok-123'])
        ->postJson(route('webhooks.email.receive', $integration), [
            'From' => 'ana@acme.com',
            'Subject' => 'I need help',
            'TextBody' => 'hello',
            'MessageID' => 'pm-1',
        ])
        ->assertStatus(202);

    Queue::assertPushed(
        DispatchEmailInboundWorkflows::class,
        fn (DispatchEmailInboundWorkflows $job): bool => $job->integrationId === $integration->id
            && ($job->payload['email']['subject'] ?? null) === 'I need help'
            && ($job->payload['email']['from'] ?? null) === 'ana@acme.com',
    );
});

it('accepts an email via a valid HMAC signature', function () {
    Queue::fake();
    $secret = 'sig-secret';
    $integration = emailIntegration(['webhook_secret' => $secret, 'email_provider' => 'generic']);
    $body = ['from' => 'ana@acme.com', 'subject' => 'Hi', 'text' => 'x'];
    $sig = 'sha256='.hash_hmac('sha256', json_encode($body), $secret);

    test()->withHeaders(['X-Hub-Signature-256' => $sig])
        ->postJson(route('webhooks.email.receive', $integration), $body)
        ->assertStatus(202);

    Queue::assertPushed(DispatchEmailInboundWorkflows::class);
});

it('rejects an email with neither a valid token nor signature', function () {
    Queue::fake();
    $integration = emailIntegration(['webhook_secret' => 'tok-123']);

    test()->withHeaders(['X-Webhook-Token' => 'wrong'])
        ->postJson(route('webhooks.email.receive', $integration), ['from' => 'a@b.com'])
        ->assertStatus(401);

    Queue::assertNotPushed(DispatchEmailInboundWorkflows::class);
});

it('404s when the integration has no inbound configured', function () {
    Queue::fake();
    $integration = emailIntegration([]);

    test()->postJson(route('webhooks.email.receive', $integration), ['from' => 'a@b.com'])
        ->assertNotFound();

    Queue::assertNotPushed(DispatchEmailInboundWorkflows::class);
});
