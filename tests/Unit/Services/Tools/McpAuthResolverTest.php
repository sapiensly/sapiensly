<?php

use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\User;
use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;
use App\Services\Tools\McpAuthResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = new McpAuthResolver(new OAuth2TokenRefresher);
});

it('returns no headers for none auth', function () {
    expect($this->resolver->resolveHeaders(['auth_type' => 'none']))->toBe([]);
});

it('builds a bearer header', function () {
    expect($this->resolver->resolveHeaders([
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'abc123'],
    ]))->toBe(['Authorization' => 'Bearer abc123']);
});

it('omits the bearer header when the token is empty', function () {
    expect($this->resolver->resolveHeaders([
        'auth_type' => 'bearer',
        'auth_config' => ['token' => ''],
    ]))->toBe([]);
});

it('builds an api key header', function () {
    expect($this->resolver->resolveHeaders([
        'auth_type' => 'api_key',
        'auth_config' => ['location' => 'header', 'name' => 'X-Api-Key', 'value' => 'secret'],
    ]))->toBe(['X-Api-Key' => 'secret']);
});

it('builds a basic auth header', function () {
    expect($this->resolver->resolveHeaders([
        'auth_type' => 'basic',
        'auth_config' => ['username' => 'user', 'password' => 'pass'],
    ]))->toBe(['Authorization' => 'Basic '.base64_encode('user:pass')]);
});

it('throws when oauth2 has no linked integration', function () {
    $this->resolver->resolveHeaders(['auth_type' => 'oauth2']);
})->throws(RuntimeException::class, 'no integration is linked');

it('throws when oauth2 has no user context', function () {
    $integration = Integration::factory()->oauth2AuthCode()->create();

    $this->resolver->resolveHeaders([
        'auth_type' => 'oauth2',
        'integration_id' => $integration->id,
    ]);
})->throws(RuntimeException::class, 'user context');

it('uses the current user token for the linked integration', function () {
    $user = User::factory()->create();
    $integration = Integration::factory()->oauth2AuthCode()->create();
    IntegrationUserToken::create([
        'user_id' => $user->id,
        'integration_id' => $integration->id,
        'auth_config' => ['access_token' => 'my-token', 'expires_at' => time() + 3600],
    ]);

    $headers = $this->resolver->resolveHeaders([
        'auth_type' => 'oauth2',
        'integration_id' => $integration->id,
    ], $user);

    expect($headers)->toBe(['Authorization' => 'Bearer my-token']);
});

it('throws when the current user has not authorized the tool', function () {
    $user = User::factory()->create();
    $integration = Integration::factory()->oauth2AuthCode()->create();

    $this->resolver->resolveHeaders([
        'auth_type' => 'oauth2',
        'integration_id' => $integration->id,
    ], $user);
})->throws(RuntimeException::class, 'not authorized this tool');

it('refreshes an expired user token and persists the new one', function () {
    Http::fake([
        'https://auth.example.com/oauth/token' => Http::response([
            'access_token' => 'refreshed-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
        ], 200),
    ]);

    $user = User::factory()->create();
    $integration = Integration::factory()->oauth2AuthCode()->create([
        'auth_config' => [
            'token_url' => 'https://auth.example.com/oauth/token',
            'client_id' => 'cid',
            'client_secret' => 'csec',
        ],
    ]);
    $token = IntegrationUserToken::create([
        'user_id' => $user->id,
        'integration_id' => $integration->id,
        'auth_config' => ['access_token' => 'old', 'refresh_token' => 'old-refresh', 'expires_at' => time() - 10],
    ]);

    $headers = $this->resolver->resolveHeaders([
        'auth_type' => 'oauth2',
        'integration_id' => $integration->id,
    ], $user);

    expect($headers)->toBe(['Authorization' => 'Bearer refreshed-token'])
        ->and($token->fresh()->auth_config['access_token'])->toBe('refreshed-token');
});
