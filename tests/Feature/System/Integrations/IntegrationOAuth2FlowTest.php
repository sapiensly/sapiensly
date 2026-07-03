<?php

use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\OAuth2\OAuth2AuthorizationCodeFlow;
use App\Services\Integrations\OAuth2\OAuth2ClientCredentialsFlow;
use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('client credentials flow fetches and caches an access token', function () {
    Http::fake([
        'https://auth.example.com/oauth/token' => Http::response([
            'access_token' => 'new-access-token',
            'expires_in' => 3600,
        ], 200),
    ]);

    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->oauth2ClientCreds()->forUser($user)->create();

    $flow = app(OAuth2ClientCredentialsFlow::class);
    $refreshed = $flow->acquire($integration);

    expect($refreshed->auth_config['access_token'])->toBe('new-access-token')
        ->and($refreshed->auth_config['expires_at'])->toBeGreaterThan(time());
});

test('refresher is a no-op when the cached token is still valid', function () {
    Http::fake();   // any outbound call would throw

    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->oauth2ClientCreds()->forUser($user)->create([
        'auth_config' => [
            'token_url' => 'https://auth.example.com/oauth/token',
            'client_id' => 'cid', 'client_secret' => 'csec',
            'access_token' => 'still-fresh',
            'expires_at' => time() + 600,
        ],
    ]);

    $refreshed = app(OAuth2TokenRefresher::class)->refreshIfNeeded($integration);

    expect($refreshed->auth_config['access_token'])->toBe('still-fresh');
    Http::assertNothingSent();
});

test('authorization code flow builds the provider URL with PKCE params', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->oauth2AuthCode()->forUser($user)->create();

    $prepared = app(OAuth2AuthorizationCodeFlow::class)->buildAuthorizeUrl($integration);

    expect($prepared['url'])->toContain('response_type=code')
        ->and($prepared['url'])->toContain('code_challenge=')
        ->and($prepared['url'])->toContain('code_challenge_method=S256')
        ->and($prepared['state'])->not->toBeEmpty()
        ->and($prepared['code_verifier'])->not->toBeEmpty();
});

test('authorization code callback rejects state mismatch', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->oauth2AuthCode()->forUser($user)->create();

    app(OAuth2AuthorizationCodeFlow::class)->handleCallback(
        integration: $integration,
        code: 'any',
        stateFromProvider: 'wrong-state',
        stateExpected: 'expected-state',
        codeVerifier: 'v',
    );
})->throws(RuntimeException::class, 'state mismatch');

test('authorization code callback exchanges the code for tokens', function () {
    Http::fake([
        'https://auth.example.com/oauth/token' => Http::response([
            'access_token' => 'ac-access',
            'refresh_token' => 'ac-refresh',
            'expires_in' => 3600,
        ], 200),
    ]);

    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->oauth2AuthCode()->forUser($user)->create();

    $result = app(OAuth2AuthorizationCodeFlow::class)->handleCallback(
        integration: $integration,
        code: 'provider-code',
        stateFromProvider: 'xyz',
        stateExpected: 'xyz',
        codeVerifier: 'verifier-string',
    );

    expect($result->auth_config['access_token'])->toBe('ac-access')
        ->and($result->auth_config['refresh_token'])->toBe('ac-refresh');
});

test('refresher uses refresh_token when present', function () {
    Http::fake([
        'https://auth.example.com/oauth/token' => Http::response([
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 3600,
        ], 200),
    ]);

    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->oauth2AuthCode()->forUser($user)->create([
        'auth_config' => [
            'token_url' => 'https://auth.example.com/oauth/token',
            'client_id' => 'cid', 'client_secret' => 'csec',
            'access_token' => 'old',
            'refresh_token' => 'old-refresh',
            'expires_at' => time() - 10,  // expired
        ],
    ]);

    $refreshed = app(OAuth2TokenRefresher::class)->refreshIfNeeded($integration);

    expect($refreshed->auth_config['access_token'])->toBe('rotated-access')
        ->and($refreshed->auth_config['refresh_token'])->toBe('rotated-refresh');
});

test('an auth-code connection without a refresh token asks for re-authorization instead of trying client_credentials', function () {
    // A public PKCE client can never mint tokens via client_credentials — the
    // provider answers a cryptic unauthorized_client. The refresher must fail
    // with the actionable message and never call the token endpoint.
    Http::fake([
        'https://auth.example.com/*' => Http::response(['error' => 'unauthorized_client'], 400),
    ]);

    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->oauth2AuthCode()->forUser($user)->create([
        'auth_config' => [
            'token_url' => 'https://auth.example.com/oauth/token',
            'client_id' => 'cid', 'client_secret' => null, 'pkce' => true,
            'access_token' => 'stale',
            'expires_at' => time() - 10, // expired, and no refresh_token stored
        ],
    ]);

    try {
        app(OAuth2TokenRefresher::class)->refreshIfNeeded($integration);
        $this->fail('Expected a re-authorization RuntimeException.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('re-authorize');
    }

    Http::assertNothingSent();
});

test('refresher surfaces provider errors', function () {
    Http::fake([
        'https://auth.example.com/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->oauth2ClientCreds()->forUser($user)->create([
        'auth_config' => [
            'token_url' => 'https://auth.example.com/oauth/token',
            'client_id' => 'cid', 'client_secret' => 'csec',
        ],
    ]);

    app(OAuth2TokenRefresher::class)->refreshIfNeeded($integration);
})->throws(RuntimeException::class, 'OAuth2');

test('the org-level integration authorize route no longer exists', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->oauth2AuthCode()->forUser($user)->create();

    actingAs($user)
        ->get("/oauth/integrations/{$integration->id}/authorize")
        ->assertNotFound();
});
