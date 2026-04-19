<?php

use App\Models\Integration;
use App\Models\IntegrationEnvironment;
use App\Models\IntegrationVariable;
use App\Models\Organization;
use App\Models\User;
use App\Services\Integrations\Support\VariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = new VariableResolver;
});

function makeEnvironment(): IntegrationEnvironment
{
    $org = Organization::create(['name' => 'O', 'slug' => 'vr-'.uniqid()]);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $integration = Integration::factory()->forOrganization($org, $user)->create();

    return IntegrationEnvironment::factory()->forIntegration($integration)->create(['name' => 'dev']);
}

test('resolve returns template unchanged when no variables match', function () {
    $env = makeEnvironment();
    expect($this->resolver->resolve('hello {{missing}}', [], $env))->toBe('hello {{missing}}');
});

test('resolve substitutes variables from the environment', function () {
    $env = makeEnvironment();
    IntegrationVariable::factory()->forEnvironment($env)->create(['key' => 'host', 'value' => 'api.example.com']);

    expect($this->resolver->resolve('https://{{host}}/v1', [], $env))
        ->toBe('https://api.example.com/v1');
});

test('resolve prefers runtime overrides over environment values', function () {
    $env = makeEnvironment();
    IntegrationVariable::factory()->forEnvironment($env)->create(['key' => 'host', 'value' => 'stored.example.com']);

    $result = $this->resolver->resolve('https://{{host}}/x', ['host' => 'runtime.example.com'], $env);

    expect($result)->toBe('https://runtime.example.com/x');
});

test('resolve with null environment falls back to runtime-only', function () {
    expect($this->resolver->resolve('{{token}}', ['token' => 't-123']))
        ->toBe('t-123');
});

test('resolveJson escapes string values to preserve JSON validity', function () {
    $env = makeEnvironment();
    IntegrationVariable::factory()->forEnvironment($env)->create(['key' => 'name', 'value' => 'He said "hi"']);

    $json = $this->resolver->resolveJson('{"name": "{{name}}"}', [], $env);

    expect($json)->toBe('{"name": "He said \\"hi\\""}');
    expect(json_decode($json, true))->toBe(['name' => 'He said "hi"']);
});

test('extractTokens lists referenced variables', function () {
    $tokens = $this->resolver->extractTokens('GET /{{path}} with header {{ token }} and {{path}} twice');
    expect($tokens)->toEqualCanonicalizing(['path', 'token']);
});
