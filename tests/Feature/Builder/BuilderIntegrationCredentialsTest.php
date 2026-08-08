<?php

use App\Enums\IntegrationAuthType;
use App\Models\App;
use App\Models\Integration;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The provisioning card answers "authorize this connection" where it is asked.
 *
 * The card told people a connection needed a secret and then had nowhere for
 * them to put it: the only route was the integrations admin, a long edit form,
 * and back. The secret still never passes through the model — it is posted from
 * the browser to this endpoint, and the conversation learns only the outcome.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['organization_id' => null]);
    // NOT $this->app — that is the Laravel container on the TestCase.
    $this->builtApp = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => null]);
});

test('a bearer secret typed into the card authorizes the connection', function () {
    $integration = Integration::factory()->forUser($this->user)->create([
        'auth_type' => IntegrationAuthType::BearerToken,
        'auth_config' => [],
        'status' => 'draft',
    ]);

    actingAs($this->user)
        ->postJson(route('apps.builder.integrations.credentials', [$this->builtApp, $integration]), [
            'secret' => 'sk-live-abcdef',
        ])
        ->assertOk()
        ->assertJson(['ok' => true, 'authorized' => true]);

    $fresh = $integration->fresh();

    expect($fresh->auth_config['token'])->toBe('sk-live-abcdef')
        ->and($fresh->status)->toBe('active');
});

test('an api key lands under the header name the provider expects', function () {
    $integration = Integration::factory()->forUser($this->user)->create([
        'auth_type' => IntegrationAuthType::ApiKey,
        'auth_config' => ['location' => 'header', 'name' => 'X-Api-Key'],
        'status' => 'draft',
    ]);

    actingAs($this->user)
        ->postJson(route('apps.builder.integrations.credentials', [$this->builtApp, $integration]), [
            'secret' => 'key-123',
        ])
        ->assertOk();

    expect($integration->fresh()->auth_config)
        ->toMatchArray(['location' => 'header', 'name' => 'X-Api-Key', 'value' => 'key-123']);
});

test('a connection needing more than one secret is refused, not half-captured', function () {
    // Basic auth wants a username too, and OAuth has its own consent route —
    // capturing half a credential would leave a connection that looks finished
    // and fails on first call.
    $integration = Integration::factory()->forUser($this->user)->create([
        'auth_type' => IntegrationAuthType::BasicAuth,
        'auth_config' => [],
        'status' => 'draft',
    ]);

    actingAs($this->user)
        ->postJson(route('apps.builder.integrations.credentials', [$this->builtApp, $integration]), [
            'secret' => 'hunter2',
        ])
        ->assertStatus(422);

    expect($integration->fresh()->auth_config)->toBe([]);
});

test('a stranger cannot push credentials into a connection through an app', function () {
    $integration = Integration::factory()->forUser($this->user)->create([
        'auth_type' => IntegrationAuthType::BearerToken,
        'auth_config' => [],
        'status' => 'draft',
    ]);
    $stranger = User::factory()->create(['organization_id' => null]);

    actingAs($stranger)
        ->postJson(route('apps.builder.integrations.credentials', [$this->builtApp, $integration]), [
            'secret' => 'sk-live-stolen',
        ])
        ->assertForbidden();

    expect($integration->fresh()->auth_config)->toBe([]);
});

test('the recheck endpoint says HOW a connection would be authorized', function () {
    // Without this the card can only link out to the admin surface, which is
    // not where authorization happens for either kind of connection.
    $oauth = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();
    $bearer = Integration::factory()->bearer()->forUser($this->user)->create();

    $payload = actingAs($this->user)
        ->getJson(route('apps.builder.connector-actions', $this->builtApp))
        ->assertOk()
        ->json('integrations');

    $byId = collect($payload)->keyBy('id');

    expect($byId[$oauth->id]['authorize_url'])
        ->toBe(route('integrations.oauth2.authorize', $oauth, absolute: false))
        ->and($byId[$oauth->id]['secret_field'])->toBeNull()
        ->and($byId[$bearer->id]['authorize_url'])->toBeNull()
        ->and($byId[$bearer->id]['secret_field'])->toBe('token');
});
