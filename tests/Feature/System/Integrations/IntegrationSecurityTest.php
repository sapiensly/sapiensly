<?php

use App\Models\Integration;
use App\Models\IntegrationExecution;
use App\Models\IntegrationRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create(['organization_id' => null]);
});

test('SSRF guard blocks execute against a loopback base URL', function () {
    $integration = Integration::factory()->forUser($this->user)->create(['base_url' => 'http://127.0.0.1']);
    $request = IntegrationRequest::factory()->forIntegration($integration)->create(['path' => '/']);

    $response = actingAs($this->user)
        ->postJson("/system/integrations/requests/{$request->id}/execute")
        ->assertOk()
        ->json();

    expect($response['success'])->toBeFalse()
        ->and($response['error'])->toContain('blocked');
});

test('SSRF guard blocks against a link-local cloud-metadata IP', function () {
    $integration = Integration::factory()->forUser($this->user)->create(['base_url' => 'http://169.254.169.254']);
    $request = IntegrationRequest::factory()->forIntegration($integration)->create(['path' => '/']);

    $response = actingAs($this->user)
        ->postJson("/system/integrations/requests/{$request->id}/execute")
        ->assertOk()
        ->json();

    expect($response['success'])->toBeFalse();
});

test('stored execution redacts Authorization and cookie headers', function () {
    Http::fake(['api.example.com/*' => Http::response('ok', 200)]);

    $integration = Integration::factory()->bearer()->forUser($this->user)->create(['base_url' => 'https://api.example.com']);
    $request = IntegrationRequest::factory()->forIntegration($integration)->create([
        'path' => '/',
        'headers' => [
            ['key' => 'Cookie', 'value' => 'session=abc', 'enabled' => true],
        ],
    ]);

    actingAs($this->user)->postJson("/system/integrations/requests/{$request->id}/execute")->assertOk();

    $execution = IntegrationExecution::latest()->first();
    expect($execution->request_headers['Authorization'])->toBe('[REDACTED]')
        ->and($execution->request_headers['Cookie'])->toBe('[REDACTED]');
});

test('stored URL redacts token-style query params', function () {
    Http::fake(['api.example.com/*' => Http::response('ok', 200)]);

    $integration = Integration::factory()->forUser($this->user)->create(['base_url' => 'https://api.example.com']);
    $request = IntegrationRequest::factory()->forIntegration($integration)->create([
        'path' => '/search',
        'query_params' => [
            ['key' => 'api_key', 'value' => 'leak-me', 'enabled' => true],
            ['key' => 'q', 'value' => 'foo', 'enabled' => true],
        ],
    ]);

    actingAs($this->user)->postJson("/system/integrations/requests/{$request->id}/execute")->assertOk();

    $execution = IntegrationExecution::latest()->first();
    expect($execution->url)->toContain('api_key=[REDACTED]')
        ->and($execution->url)->toContain('q=foo');
});

test('response truncation is flagged when body exceeds the store cap', function () {
    $bigBody = str_repeat('a', 2_000_000);
    Http::fake(['api.example.com/*' => Http::response($bigBody, 200)]);

    $integration = Integration::factory()->forUser($this->user)->create(['base_url' => 'https://api.example.com']);
    $request = IntegrationRequest::factory()->forIntegration($integration)->create(['path' => '/']);

    $json = actingAs($this->user)
        ->postJson("/system/integrations/requests/{$request->id}/execute")
        ->assertOk()
        ->json();

    expect($json['response_truncated'])->toBeTrue()
        ->and($json['response_size_bytes'])->toBe(2_000_000);

    $execution = IntegrationExecution::latest()->first();
    expect($execution->metadata['truncated'])->toBeTrue()
        ->and(strlen($execution->response_body ?? ''))->toBeLessThanOrEqual(1_048_576);
});
