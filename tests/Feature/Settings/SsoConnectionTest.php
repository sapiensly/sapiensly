<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationSsoConnection;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

function ownerOf(Organization $org): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);

    return $user;
}

function memberOf(Organization $org): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => MembershipRole::Member,
        'status' => MembershipStatus::Active,
    ]);

    return $user;
}

test('an owner can view the SSO settings page', function () {
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $owner = ownerOf($org);

    actingAs($owner)->get('/settings/sso')->assertSuccessful();
});

test('a member cannot view the SSO settings page', function () {
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $member = memberOf($org);

    actingAs($member)->get('/settings/sso')->assertForbidden();
});

test('a member cannot update the SSO connection', function () {
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $member = memberOf($org);

    actingAs($member)
        ->put('/settings/sso', ['enabled' => false, 'auto_provision' => true])
        ->assertForbidden();

    expect(OrganizationSsoConnection::count())->toBe(0);
});

test('an owner can save and enable the connection, discovering endpoints', function () {
    Http::fake([
        'https://idp.example.com/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/authorize',
            'token_endpoint' => 'https://idp.example.com/token',
            'userinfo_endpoint' => 'https://idp.example.com/userinfo',
            'jwks_uri' => 'https://idp.example.com/jwks',
            'scopes_supported' => ['openid', 'email', 'profile'],
            'code_challenge_methods_supported' => ['S256'],
        ]),
    ]);

    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $owner = ownerOf($org);

    actingAs($owner)->put('/settings/sso', [
        'enabled' => true,
        'auto_provision' => true,
        'issuer' => 'https://idp.example.com',
        'client_id' => 'client-abc',
        'client_secret' => 'super-secret',
        'allowed_domains' => ['Example.com', 'example.com'],
    ])->assertRedirect();

    $connection = OrganizationSsoConnection::where('organization_id', $org->id)->first();
    expect($connection)->not->toBeNull()
        ->and($connection->enabled)->toBeTrue()
        ->and($connection->client_id)->toBe('client-abc')
        ->and($connection->config['client_secret'])->toBe('super-secret')
        ->and($connection->config['authorize_url'])->toBe('https://idp.example.com/authorize')
        ->and($connection->config['userinfo_url'])->toBe('https://idp.example.com/userinfo')
        // Domains are normalised + de-duplicated.
        ->and($connection->allowed_domains)->toBe(['example.com']);
});

test('a blank secret on update keeps the stored one', function () {
    Http::fake([
        'https://idp.example.com/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/authorize',
            'token_endpoint' => 'https://idp.example.com/token',
            'userinfo_endpoint' => 'https://idp.example.com/userinfo',
            'scopes_supported' => ['openid', 'email'],
            'code_challenge_methods_supported' => ['S256'],
        ]),
    ]);

    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    $owner = ownerOf($org);
    OrganizationSsoConnection::create([
        'organization_id' => $org->id,
        'enabled' => true,
        'auto_provision' => true,
        'issuer' => 'https://idp.example.com',
        'client_id' => 'client-abc',
        'config' => ['client_secret' => 'existing-secret', 'authorize_url' => 'https://idp.example.com/authorize'],
    ]);

    actingAs($owner)->put('/settings/sso', [
        'enabled' => true,
        'auto_provision' => true,
        'issuer' => 'https://idp.example.com',
        'client_id' => 'client-abc',
        'client_secret' => '',
    ])->assertRedirect();

    expect(OrganizationSsoConnection::where('organization_id', $org->id)->first()->config['client_secret'])
        ->toBe('existing-secret');
});
