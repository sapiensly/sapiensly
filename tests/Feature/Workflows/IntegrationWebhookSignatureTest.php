<?php

use App\Models\Integration;
use App\Services\Workflows\IntegrationWebhookSignature;

beforeEach(function () {
    $this->sig = new IntegrationWebhookSignature;
});

function integrationWith(array $auth): Integration
{
    $integration = new Integration;
    $integration->auth_config = $auth;

    return $integration;
}

it('verifies an HMAC-SHA256 body signature (bare hex and sha256= prefix)', function () {
    $body = '{"action":"opened"}';
    $secret = 'top-secret';
    $integration = integrationWith(['webhook_secret' => $secret]);
    $digest = hash_hmac('sha256', $body, $secret);

    expect($this->sig->verify($integration, $body, $digest))->toBeTrue()
        ->and($this->sig->verify($integration, $body, 'sha256='.$digest))->toBeTrue()
        ->and($this->sig->verify($integration, $body, 'sha256=deadbeef'))->toBeFalse()
        ->and($this->sig->verify($integration, $body.'tampered', $digest))->toBeFalse();
});

it('fails closed when no secret is configured or the scheme is unknown', function () {
    $body = '{"a":1}';

    expect($this->sig->isEnabled(integrationWith([])))->toBeFalse()
        ->and($this->sig->verify(integrationWith([]), $body, 'anything'))->toBeFalse()
        ->and($this->sig->verify(integrationWith(['webhook_secret' => 's']), $body, null))->toBeFalse()
        ->and($this->sig->verify(
            integrationWith(['webhook_secret' => 's', 'webhook_signature_scheme' => 'stripe']),
            $body,
            hash_hmac('sha256', $body, 's'),
        ))->toBeFalse();
});

it('resolves the signature header, defaulting to X-Hub-Signature-256', function () {
    expect($this->sig->headerName(integrationWith(['webhook_secret' => 's'])))->toBe('X-Hub-Signature-256')
        ->and($this->sig->headerName(integrationWith(['webhook_secret' => 's', 'webhook_signature_header' => 'X-Signature'])))
        ->toBe('X-Signature');
});
