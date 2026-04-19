<?php

use App\Models\Integration;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('index page loads for authenticated users', function () {
    $user = User::factory()->create(['organization_id' => null]);
    Integration::factory()->forUser($user)->count(2)->create();

    actingAs($user)
        ->get('/system/integrations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/integrations/Index')
            ->has('integrations', 2));
});

test('create page renders the form', function () {
    $user = User::factory()->create(['organization_id' => null]);

    actingAs($user)
        ->get('/system/integrations/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/integrations/Form')
            ->where('mode', 'create')
            ->has('authTypes', 7));
});

test('store creates an integration and redirects to show', function () {
    $user = User::factory()->create(['organization_id' => null]);

    actingAs($user)->post('/system/integrations', [
        'name' => 'Stripe',
        'base_url' => 'https://api.stripe.com',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'sk_test_abc'],
    ])->assertRedirect();

    $integration = Integration::where('name', 'Stripe')->first();
    expect($integration)->not->toBeNull()
        ->and($integration->user_id)->toBe($user->id)
        ->and($integration->auth_config['token'])->toBe('sk_test_abc');
});

test('store fails validation when base_url is not http(s)', function () {
    $user = User::factory()->create(['organization_id' => null]);

    actingAs($user)->post('/system/integrations', [
        'name' => 'Bad',
        'base_url' => 'ftp://nope',
        'auth_type' => 'none',
    ])->assertSessionHasErrors('base_url');
});

test('show returns the integration for its owner', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->forUser($user)->create();

    actingAs($user)
        ->get("/system/integrations/{$integration->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/integrations/Show')
            ->where('integration.id', $integration->id));
});

test('show is forbidden for another user in personal mode', function () {
    $owner = User::factory()->create(['organization_id' => null]);
    $other = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->forUser($owner)->create();

    actingAs($other)
        ->get("/system/integrations/{$integration->id}")
        ->assertForbidden();
});

test('update merges auth_config without overwriting secrets passed as blank', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->bearer()->forUser($user)->create();
    $originalToken = $integration->auth_config['token'];

    actingAs($user)->put("/system/integrations/{$integration->id}", [
        'name' => 'New name',
        'base_url' => 'https://new.example.com',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => ''],  // "keep existing"
    ])->assertRedirect();

    expect($integration->fresh()->name)->toBe('New name')
        ->and($integration->fresh()->auth_config['token'])->toBe($originalToken);
});

test('destroy removes the integration', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->forUser($user)->create();

    actingAs($user)->delete("/system/integrations/{$integration->id}")
        ->assertRedirect('/system/integrations');

    expect(Integration::find($integration->id))->toBeNull();
});

test('duplicate copies the integration and all its nested resources', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->bearer()->forUser($user)->create();
    $integration->environments()->create(['name' => 'Dev']);
    $integration->requests()->create([
        'name' => 'Ping', 'method' => 'GET', 'path' => '/ping', 'sort_order' => 1,
    ]);

    actingAs($user)->post("/system/integrations/{$integration->id}/duplicate")
        ->assertRedirect();

    expect(Integration::count())->toBe(2);
    $copy = Integration::where('name', 'like', '%copy%')->first();
    expect($copy->environments)->toHaveCount(1)
        ->and($copy->requests)->toHaveCount(1);
});

test('global visibility is rejected for non-sysadmin via policy when updating', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $global = Integration::factory()->global()->create();

    actingAs($user)->put("/system/integrations/{$global->id}", [
        'name' => 'Hacked',
        'base_url' => 'https://x.com',
        'auth_type' => 'none',
    ])->assertForbidden();
});
