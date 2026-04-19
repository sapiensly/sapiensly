<?php

use App\Models\Integration;
use App\Models\IntegrationEnvironment;
use App\Models\IntegrationExecution;
use App\Models\IntegrationRequest;
use App\Models\IntegrationVariable;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create(['organization_id' => null]);
});

function makeIntegrationWithRequest(User $user, array $integrationOverrides = [], array $requestOverrides = []): array
{
    $integration = Integration::factory()->forUser($user)->create(array_merge([
        'base_url' => 'https://api.example.com',
    ], $integrationOverrides));

    $request = IntegrationRequest::factory()->forIntegration($integration)->create(array_merge([
        'method' => 'GET',
        'path' => '/users',
    ], $requestOverrides));

    return [$integration, $request];
}

test('successful GET request returns response body and records execution', function () {
    Http::fake([
        'api.example.com/*' => Http::response(['ok' => true, 'users' => []], 200),
    ]);

    [$integration, $request] = makeIntegrationWithRequest($this->user);

    $response = actingAs($this->user)
        ->postJson("/system/integrations/requests/{$request->id}/execute")
        ->assertOk()
        ->json();

    expect($response['success'])->toBeTrue()
        ->and($response['status'])->toBe(200)
        ->and($response['response_body'])->toContain('"ok"')
        ->and(IntegrationExecution::count())->toBe(1);
});

test('failed upstream request is recorded with success=false', function () {
    Http::fake(['api.example.com/*' => Http::response(['error' => 'nope'], 500)]);

    [, $request] = makeIntegrationWithRequest($this->user);

    $response = actingAs($this->user)
        ->postJson("/system/integrations/requests/{$request->id}/execute")
        ->assertOk()
        ->json();

    expect($response['success'])->toBeFalse()
        ->and($response['status'])->toBe(500);
    expect(IntegrationExecution::latest()->first()->success)->toBeFalse();
});

test('bearer auth applies an Authorization header', function () {
    Http::fake(['api.example.com/*' => Http::response('ok', 200)]);

    [, $request] = makeIntegrationWithRequest($this->user, ['auth_type' => 'bearer', 'auth_config' => ['token' => 'tok-123']]);

    actingAs($this->user)->postJson("/system/integrations/requests/{$request->id}/execute")->assertOk();

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok-123'));
});

test('api key auth places the credential in a query param when configured', function () {
    Http::fake(['api.example.com/*' => Http::response('ok', 200)]);

    [, $request] = makeIntegrationWithRequest($this->user, [
        'auth_type' => 'api_key',
        'auth_config' => ['location' => 'query', 'name' => 'key', 'value' => 'secret'],
    ]);

    actingAs($this->user)->postJson("/system/integrations/requests/{$request->id}/execute")->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'key=secret'));
});

test('basic auth applies a Base64-encoded Authorization header', function () {
    Http::fake(['api.example.com/*' => Http::response('ok', 200)]);

    [, $request] = makeIntegrationWithRequest($this->user, [
        'auth_type' => 'basic',
        'auth_config' => ['username' => 'alice', 'password' => 'hunter2'],
    ]);

    actingAs($this->user)->postJson("/system/integrations/requests/{$request->id}/execute")->assertOk();

    $expected = 'Basic '.base64_encode('alice:hunter2');
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', $expected));
});

test('runtime variables override environment values in the URL', function () {
    Http::fake(['api.example.com/*' => Http::response('ok', 200)]);

    [$integration, $request] = makeIntegrationWithRequest($this->user, [], ['path' => '/users/{{id}}']);

    $env = IntegrationEnvironment::factory()->forIntegration($integration)->create(['name' => 'Dev']);
    IntegrationVariable::factory()->forEnvironment($env)->create(['key' => 'id', 'value' => 'stored']);
    $integration->update(['active_environment_id' => $env->id]);

    actingAs($this->user)
        ->postJson("/system/integrations/requests/{$request->id}/execute", [
            'variables' => ['id' => 'runtime-42'],
        ])
        ->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/users/runtime-42'));
});

test('executions record includes invoked_by metadata', function () {
    Http::fake(['api.example.com/*' => Http::response('ok', 200)]);

    [, $request] = makeIntegrationWithRequest($this->user);

    actingAs($this->user)->postJson("/system/integrations/requests/{$request->id}/execute")->assertOk();

    $execution = IntegrationExecution::latest()->first();
    expect($execution->metadata['invoked_by'])->toBe('user');
});

test('ad-hoc execute runs without a persisted IntegrationRequest', function () {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

    $integration = Integration::factory()->forUser($this->user)->create(['base_url' => 'https://api.example.com']);

    $response = actingAs($this->user)
        ->postJson("/system/integrations/{$integration->id}/execute-ad-hoc", [
            'method' => 'POST',
            'path' => '/echo',
            'body_type' => 'json',
            'body_content' => '{"hello": "world"}',
        ])
        ->assertOk()
        ->json();

    expect($response['success'])->toBeTrue()
        ->and(IntegrationExecution::count())->toBe(1)
        ->and(IntegrationExecution::first()->integration_request_id)->toBeNull();
});

test('user without permission cannot execute another users integration', function () {
    Http::fake();

    [, $request] = makeIntegrationWithRequest($this->user);
    $intruder = User::factory()->create(['organization_id' => null]);

    actingAs($intruder)
        ->postJson("/system/integrations/requests/{$request->id}/execute")
        ->assertForbidden();

    expect(IntegrationExecution::count())->toBe(0);
});
