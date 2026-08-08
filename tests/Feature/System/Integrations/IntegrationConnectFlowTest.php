<?php

use App\Enums\IntegrationAuthType;
use App\Enums\Visibility;
use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\Organization;
use App\Models\User;
use App\Policies\IntegrationPolicy;
use App\Services\Integrations\IntegrationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * Authorizing a connection, from the connection.
 *
 * Every test here stands for a step of the six-screen detour this replaced: the
 * handshake used to hang off a Tool, so connecting an MCP server meant inventing
 * a tool nobody asked for; and a draft connection given its API key stayed draft
 * for ever, because the only thing that could have promoted it was a form field
 * that was never rendered and would have failed validation anyway.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['organization_id' => null]);
});

test('a member connects their own account from the connection itself', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();

    actingAs($this->user)
        ->get(route('integrations.oauth2.authorize', $integration))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertRedirectContains('https://auth.example.com/oauth/authorize');

    expect(session('integrations.oauth2.state.integration_id'))->toBe($integration->id)
        ->and(session('integrations.oauth2.state.user_id'))->toBe($this->user->id);
});

test('the handshake returns to where it started', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();

    actingAs($this->user)
        ->get(route('integrations.oauth2.authorize', $integration, absolute: false).'?return_to=%2Fr%2Fcampo%2Fdashboard')
        ->assertRedirect();

    expect(session('integrations.oauth2.state.return_to'))->toBe('/r/campo/dashboard');
});

test('return_to cannot be used to bounce the user off this host', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();

    foreach (['https://evil.test/steal', '//evil.test/steal'] as $hostile) {
        actingAs($this->user)
            ->get(route('integrations.oauth2.authorize', $integration, absolute: false).'?return_to='.urlencode($hostile))
            ->assertRedirect();

        expect(session('integrations.oauth2.state.return_to'))
            ->toBe(route('system.integrations.show', $integration));
    }
});

test('a connection-started handshake stores the token against the current user only', function () {
    Http::fake([
        'https://auth.example.com/oauth/token' => Http::response([
            'access_token' => 'user-access',
            'expires_in' => 3600,
        ], 200),
    ]);

    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();

    actingAs($this->user)
        ->withSession(['integrations.oauth2.state' => [
            'integration_id' => $integration->id,
            'user_id' => $this->user->id,
            'state' => 'st4te',
            'code_verifier' => 'verifier',
            'return_to' => '/r/campo/dashboard',
        ]])
        ->get(route('integrations.oauth2.callback', ['code' => 'c0de', 'state' => 'st4te']))
        ->assertRedirect('/r/campo/dashboard');

    expect(IntegrationUserToken::where('user_id', $this->user->id)
        ->where('integration_id', $integration->id)
        ->firstOrFail()
        ->auth_config['access_token'])->toBe('user-access')
        // The shared connection must not have absorbed one person's token.
        ->and($integration->fresh()->auth_config['access_token'] ?? null)->toBeNull();
});

test('someone else\'s connection cannot be authorized', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();
    $stranger = User::factory()->create(['organization_id' => null]);

    actingAs($stranger)
        ->get(route('integrations.oauth2.authorize', $integration))
        ->assertForbidden();
});

test('connecting your own account needs no integrations permission', function () {
    // The people this exists for — a technician opening a built app whose
    // dashboard reads live — administer nothing. Gating this on
    // `integrations.view` would put the connect button behind a permission they
    // will never hold.
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $organization = Organization::create(['name' => 'Campo', 'slug' => 'campo-'.uniqid()]);
    $member = User::factory()->create(['organization_id' => $organization->id]);
    setPermissionsTeamId($organization->id);

    $integration = Integration::factory()->oauth2AuthCode()->create([
        'user_id' => $member->id,
        'organization_id' => $organization->id,
        'visibility' => Visibility::Organization,
    ]);

    $policy = new IntegrationPolicy;

    expect($policy->view($member, $integration))->toBeFalse()
        ->and($policy->connect($member, $integration))->toBeTrue();
});

test('a draft connection becomes active the moment it holds its credentials', function () {
    $integration = Integration::factory()->forUser($this->user)->create([
        'auth_type' => IntegrationAuthType::BearerToken,
        'auth_config' => [],
        'status' => 'draft',
    ]);

    $updated = app(IntegrationService::class)->update($integration, [
        'auth_config' => ['token' => 'sk-live-1234'],
    ]);

    expect($updated->status)->toBe('active');
});

test('a draft with no credentials yet stays a draft', function () {
    $integration = Integration::factory()->forUser($this->user)->create([
        'auth_type' => IntegrationAuthType::BearerToken,
        'auth_config' => [],
        'status' => 'draft',
    ]);

    $updated = app(IntegrationService::class)->update($integration, ['description' => 'still setting up']);

    expect($updated->status)->toBe('draft');
});

test('editing a builder-created draft no longer 422s on a field nobody can see', function () {
    // The edit form used to submit `status: "draft"`, which the update rules
    // reject (in:active,inactive) — so the save that entered the API key failed
    // on a field the form never rendered.
    $integration = Integration::factory()->forUser($this->user)->create([
        'auth_type' => IntegrationAuthType::BearerToken,
        'auth_config' => [],
        'status' => 'draft',
    ]);

    actingAs($this->user)
        ->put(route('system.integrations.update', $integration), [
            'name' => $integration->name,
            'base_url' => 'https://api.example.com',
            'auth_type' => IntegrationAuthType::BearerToken->value,
            'auth_config' => ['token' => 'sk-live-9999'],
            'visibility' => 'private',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('system.integrations.show', $integration));

    expect($integration->fresh()->status)->toBe('active');
});

test('the connection page offers the handshake and reports the viewer\'s own state', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();

    actingAs($this->user)
        ->get(route('system.integrations.show', $integration))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('integration.authorized', false)
            ->where('integration.requires_per_user_consent', true)
            ->where('integration.authorize_url', route('integrations.oauth2.authorize', $integration, absolute: false))
        );

    IntegrationUserToken::create([
        'user_id' => $this->user->id,
        'integration_id' => $integration->id,
        'auth_config' => ['access_token' => 'tok', 'expires_at' => time() + 3600],
    ]);

    actingAs($this->user)
        ->get(route('system.integrations.show', $integration))
        ->assertInertia(fn ($page) => $page->where('integration.authorized', true));
});
