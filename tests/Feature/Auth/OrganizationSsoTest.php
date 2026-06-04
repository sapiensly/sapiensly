<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationSsoConnection;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertGuest;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function ssoConnection(array $overrides = []): OrganizationSsoConnection
{
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);

    return OrganizationSsoConnection::create(array_merge([
        'organization_id' => $org->id,
        'enabled' => true,
        'auto_provision' => true,
        'issuer' => 'https://idp.example.com',
        'client_id' => 'client-abc',
        'config' => [
            'client_secret' => 'secret',
            'authorize_url' => 'https://idp.example.com/authorize',
            'token_url' => 'https://idp.example.com/token',
            'userinfo_url' => 'https://idp.example.com/userinfo',
            'scope' => 'openid email profile',
            'pkce' => true,
        ],
        'allowed_domains' => [],
    ], $overrides));
}

/** Stored session shape produced by the redirect leg. */
function ssoSession(OrganizationSsoConnection $connection, string $state = 'state-token'): array
{
    return ['sso.oidc.state' => [
        'connection_id' => $connection->id,
        'organization_id' => $connection->organization_id,
        'state' => $state,
        'nonce' => 'nonce-token',
        'code_verifier' => 'verifier-token',
    ]];
}

function fakeIdpTokens(): void
{
    Http::fake([
        'https://idp.example.com/token' => Http::response([
            'access_token' => 'at-123',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]),
        'https://idp.example.com/userinfo' => Http::response([
            'sub' => 'idp-user-1',
            'email' => 'worker@acme.test',
            'email_verified' => true,
            'name' => 'Worker One',
        ]),
    ]);
}

test('the dedicated URL redirects to the IdP and stores handshake state', function () {
    $connection = ssoConnection();

    $response = $this->get('/sso/acme');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('https://idp.example.com/authorize')
        ->toContain('state=')
        ->toContain('code_challenge=');
    $response->assertSessionHas('sso.oidc.state');
});

test('an unknown or disabled organization bounces to login', function () {
    ssoConnection(['enabled' => false]);

    $this->get('/sso/acme')->assertRedirect('/login');
    $this->get('/sso/does-not-exist')->assertRedirect('/login');
});

test('the callback provisions a new member and signs them in', function () {
    $connection = ssoConnection();
    fakeIdpTokens();

    $this->withSession(ssoSession($connection))
        ->get('/sso/callback?code=auth-code&state=state-token')
        ->assertRedirect('/dashboard');

    $user = User::where('email', 'worker@acme.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->organization_id)->toBe($connection->organization_id);

    $membership = OrganizationMembership::where('organization_id', $connection->organization_id)
        ->where('user_id', $user->id)
        ->first();
    expect($membership)->not->toBeNull()
        ->and($membership->role)->toBe(MembershipRole::Member)
        ->and($membership->status)->toBe(MembershipStatus::Active);

    assertAuthenticated();
});

test('an email outside the allowed domains is rejected', function () {
    $connection = ssoConnection(['allowed_domains' => ['allowed.test']]);
    fakeIdpTokens();

    $this->withSession(ssoSession($connection))
        ->get('/sso/callback?code=auth-code&state=state-token')
        ->assertRedirect('/login');

    expect(User::where('email', 'worker@acme.test')->exists())->toBeFalse();
    assertGuest();
});

test('a state mismatch is rejected as a possible CSRF', function () {
    $connection = ssoConnection();
    fakeIdpTokens();

    $this->withSession(ssoSession($connection, 'expected-state'))
        ->get('/sso/callback?code=auth-code&state=tampered-state')
        ->assertStatus(400);

    assertGuest();
});

test('an existing owner keeps their role after SSO sign-in', function () {
    $connection = ssoConnection();
    $owner = User::factory()->create(['email' => 'worker@acme.test']);
    OrganizationMembership::create([
        'organization_id' => $connection->organization_id,
        'user_id' => $owner->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);
    fakeIdpTokens();

    $this->withSession(ssoSession($connection))
        ->get('/sso/callback?code=auth-code&state=state-token')
        ->assertRedirect('/dashboard');

    $membership = OrganizationMembership::where('organization_id', $connection->organization_id)
        ->where('user_id', $owner->id)
        ->first();
    expect($membership->role)->toBe(MembershipRole::Owner);
});

test('a new user is refused when auto-provision is disabled', function () {
    $connection = ssoConnection(['auto_provision' => false]);
    fakeIdpTokens();

    $this->withSession(ssoSession($connection))
        ->get('/sso/callback?code=auth-code&state=state-token')
        ->assertRedirect('/login');

    expect(User::where('email', 'worker@acme.test')->exists())->toBeFalse();
    assertGuest();
});
