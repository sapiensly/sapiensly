<?php

use App\Facades\TenantCache;
use App\Models\Integration;
use App\Models\User;
use App\Services\Builder\Integrations\IntegrationAuthoring;
use App\Services\Integrations\OAuth2\OAuth2DiscoveryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

/**
 * Builder power #1 — the builder can create + verify an integration in
 * conversation. Tested at the authoring-service level (where the logic lives),
 * provider-agnostic, secrets never exposed to the caller (the LLM).
 * See docs/app-builder-create-integration-contract.md §7.
 */
beforeEach(function () {
    $this->user = User::factory()->create(); // personal context
    app(TenantContext::class)->set($this->user->organization_id, $this->user->id);
});

it('discovers an OAuth2 API and returns a secret-free summary', function () {
    $this->mock(OAuth2DiscoveryService::class, function ($m) {
        $m->shouldReceive('autoConfigure')->once()->andReturn([
            'base_url' => 'https://api.acme.test',
            'auth_type' => 'oauth2_auth_code',
            'auth_config' => [
                'authorize_url' => 'https://acme.test/oauth/authorize',
                'token_url' => 'https://acme.test/oauth/token',
                'client_id' => 'cid',
                'client_secret' => 'TOP-SECRET',
                'scope' => 'read',
            ],
            'dynamically_registered' => true,
        ]);
    });

    $result = app(IntegrationAuthoring::class)->discover('https://api.acme.test');

    expect($result['discoverable'])->toBeTrue()
        ->and($result['auth_type'])->toBe('oauth2_auth_code')
        ->and($result['cache_key'])->not->toBeNull();
    // The secret is NOT in what the LLM sees.
    expect(json_encode($result))->not->toContain('TOP-SECRET');
    // But the full config (with the secret) is stashed server-side for create().
    expect(TenantCache::get($result['cache_key'])['auth_config']['client_secret'])->toBe('TOP-SECRET');
});

it('falls back gracefully when an API is not discoverable', function () {
    $this->mock(OAuth2DiscoveryService::class, function ($m) {
        $m->shouldReceive('autoConfigure')->andThrow(new RuntimeException('no metadata'));
    });

    $result = app(IntegrationAuthoring::class)->discover('https://nope.test');

    expect($result['discoverable'])->toBeFalse();
});

it('creates a per-tenant draft with no secret for a key/bearer API', function () {
    $integration = app(IntegrationAuthoring::class)->createDraft($this->user, [
        'name' => 'Acme',
        'base_url' => 'https://api.acme.test',
        'auth_type' => 'bearer',
    ]);

    expect($integration->user_id)->toBe($this->user->id)
        ->and($integration->status)->toBe('draft')
        ->and($integration->auth_config)->toBe([]); // secret captured later, not here
});

it('pulls the discovered config from the server-side stash, not the caller', function () {
    TenantCache::put('disc-key', [
        'auth_type' => 'oauth2_auth_code',
        'base_url' => 'https://api.acme.test',
        'auth_config' => ['client_secret' => 'S', 'token_url' => 'https://acme.test/oauth/token'],
    ], 300);

    $integration = app(IntegrationAuthoring::class)->createDraft($this->user, [
        'name' => 'Acme',
        'cache_key' => 'disc-key',
    ]);

    expect($integration->auth_type->value)->toBe('oauth2_auth_code')
        ->and($integration->auth_config['client_secret'])->toBe('S')
        ->and($integration->base_url)->toBe('https://api.acme.test');
});

it('verifies a connection with the integration auth applied', function () {
    // A publicly-resolving host so the (real) SSRF guard allows it; the HTTP call
    // itself is faked. (.test hosts resolve to 127.0.0.1 under Herd and are blocked.)
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

    $integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'draft',
    ]);

    $result = app(IntegrationAuthoring::class)->test($integration, '/me');

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe(200);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'api.example.com/me')
        && $req->hasHeader('Authorization', 'Bearer TKN'));
});
