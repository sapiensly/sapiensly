<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\Visibility;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Integrations\CreateIntegrationTool;
use App\Mcp\Tools\Integrations\DeleteIntegrationTool;
use App\Mcp\Tools\Integrations\GetIntegrationTool;
use App\Mcp\Tools\Integrations\UpdateIntegrationTool;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('create_integration creates a connection and masks/encrypts the secret', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateIntegrationTool::class, [
            'name' => 'Orders API',
            'base_url' => 'https://api.example.com',
            'kind' => 'http',
            'auth_type' => 'bearer',
            'auth_config' => ['token' => 'super-secret-token-value'],
        ])
        ->assertOk()
        ->assertSee('Orders API')
        ->assertSee('http');

    $integration = Integration::where('user_id', $this->user->id)->where('name', 'Orders API')->first();
    expect($integration)->not->toBeNull();
    // Round-trips decrypted, but is never stored as plaintext (encrypted:array cast).
    expect($integration->auth_config['token'])->toBe('super-secret-token-value');
    expect($integration->getRawOriginal('auth_config'))->not->toContain('super-secret-token-value');
});

it('get_integration returns masked auth_config, kind and request count', function () {
    $integration = Integration::factory()->bearer()->create(['user_id' => $this->user->id]);

    SapiensServer::actingAs($this->user)
        ->tool(GetIntegrationTool::class, ['integration_id' => $integration->id])
        ->assertOk()
        ->assertSee($integration->name)
        ->assertSee('requests_count');
});

it('update_integration keeps the stored secret when the field is blank', function () {
    $integration = Integration::factory()->bearer()->create(['user_id' => $this->user->id]);
    $originalToken = $integration->auth_config['token'];

    SapiensServer::actingAs($this->user)
        ->tool(UpdateIntegrationTool::class, [
            'integration_id' => $integration->id,
            'description' => 'Now documented',
        ])
        ->assertOk()
        ->assertSee('Now documented');

    expect($integration->fresh()->auth_config['token'])->toBe($originalToken);
});

it('create_integration rejects an OAuth2 connection missing its client credentials', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateIntegrationTool::class, [
            'name' => 'Broken OAuth',
            'base_url' => 'https://api.example.com',
            'kind' => 'http',
            'auth_type' => 'oauth2_client_credentials',
            'auth_config' => ['token_url' => 'https://auth.example.com/token'],
        ])
        ->assertHasErrors();
});

it('delete_integration removes a connection in the caller context', function () {
    $integration = Integration::factory()->create(['user_id' => $this->user->id]);

    SapiensServer::actingAs($this->user)
        ->tool(DeleteIntegrationTool::class, ['integration_id' => $integration->id])
        ->assertOk()
        ->assertSee('deleted');

    expect(Integration::find($integration->id))->toBeNull();
});

it('create_integration makes an org user a connection their org can see', function () {
    // Regression: an org-scoped user must get an Organization-visible
    // integration (org_id set), or forAccountContext can't resolve it — found
    // running use_tool end-to-end against a real org.
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
    $owner = User::factory()->create(['organization_id' => $org->id, 'email_verified_at' => now()]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);
    setPermissionsTeamId($org->id);

    $response = SapiensServer::actingAs($owner)
        ->tool(CreateIntegrationTool::class, [
            'name' => 'Org Connection',
            'base_url' => 'https://api.example.com',
            'kind' => 'http',
            'auth_type' => 'none',
        ]);

    $response->assertOk();

    $integration = Integration::where('name', 'Org Connection')->first();
    expect($integration->visibility)->toBe(Visibility::Organization);
    expect($integration->organization_id)->toBe($org->id);

    // And it round-trips through the org-scoped resolver.
    SapiensServer::actingAs($owner)
        ->tool(GetIntegrationTool::class, ['integration_id' => $integration->id])
        ->assertOk()
        ->assertSee('Org Connection');
});

it('does not expose or mutate an integration outside the caller context', function () {
    $other = Integration::factory()->create(); // a different account's connection

    SapiensServer::actingAs($this->user)
        ->tool(GetIntegrationTool::class, ['integration_id' => $other->id])
        ->assertHasErrors();

    SapiensServer::actingAs($this->user)
        ->tool(UpdateIntegrationTool::class, ['integration_id' => $other->id, 'name' => 'Hijacked'])
        ->assertHasErrors();

    expect($other->fresh()->name)->not->toBe('Hijacked');
});
