<?php

use App\Enums\IntegrationAuthType;
use App\Services\Integrations\Auth\AuthStrategyFactory;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->factory = new AuthStrategyFactory;
});

test('None strategy emits no headers or query', function () {
    $result = $this->factory->make(IntegrationAuthType::None)->apply([]);
    expect($result)->toBe(['headers' => [], 'query' => []]);
});

test('ApiKey strategy places the credential in a header by default', function () {
    $result = $this->factory->make(IntegrationAuthType::ApiKey)->apply([
        'name' => 'X-Api-Key',
        'value' => 'abc123',
    ]);

    expect($result['headers'])->toBe(['X-Api-Key' => 'abc123'])
        ->and($result['query'])->toBe([]);
});

test('ApiKey strategy places the credential in the query string when asked', function () {
    $result = $this->factory->make(IntegrationAuthType::ApiKey)->apply([
        'location' => 'query',
        'name' => 'api_key',
        'value' => 'abc123',
    ]);

    expect($result['headers'])->toBe([])
        ->and($result['query'])->toBe(['api_key' => 'abc123']);
});

test('ApiKey strategy skips empty credentials', function () {
    $result = $this->factory->make(IntegrationAuthType::ApiKey)->apply(['name' => '', 'value' => '']);
    expect($result)->toBe(['headers' => [], 'query' => []]);
});

test('Bearer strategy emits an Authorization header with the token', function () {
    $result = $this->factory->make(IntegrationAuthType::BearerToken)->apply(['token' => 'xyz']);
    expect($result['headers'])->toBe(['Authorization' => 'Bearer xyz']);
});

test('Basic strategy base64-encodes username:password', function () {
    $result = $this->factory->make(IntegrationAuthType::BasicAuth)->apply([
        'username' => 'alice',
        'password' => 'hunter2',
    ]);

    expect($result['headers'])->toBe([
        'Authorization' => 'Basic '.base64_encode('alice:hunter2'),
    ]);
});

test('CustomHeaders strategy passes through each header', function () {
    $result = $this->factory->make(IntegrationAuthType::CustomHeaders)->apply([
        'headers' => [
            ['name' => 'X-Foo', 'value' => 'foo'],
            ['name' => 'X-Bar', 'value' => 'bar'],
            ['name' => '', 'value' => 'ignored'],   // empty name dropped
        ],
    ]);

    expect($result['headers'])->toBe(['X-Foo' => 'foo', 'X-Bar' => 'bar']);
});

test('OAuth2 ClientCredentials strategy emits the cached access token', function () {
    $result = $this->factory->make(IntegrationAuthType::OAuth2ClientCredentials)->apply([
        'access_token' => 'oauth-abc',
    ]);

    expect($result['headers'])->toBe(['Authorization' => 'Bearer oauth-abc']);
});

test('OAuth2 AuthorizationCode strategy emits the cached access token', function () {
    $result = $this->factory->make(IntegrationAuthType::OAuth2AuthorizationCode)->apply([
        'access_token' => 'oauth-xyz',
    ]);

    expect($result['headers'])->toBe(['Authorization' => 'Bearer oauth-xyz']);
});

test('OAuth2 strategies are silent when no access token is cached', function () {
    $result = $this->factory->make(IntegrationAuthType::OAuth2ClientCredentials)->apply([
        'client_id' => 'cid',
    ]);
    expect($result)->toBe(['headers' => [], 'query' => []]);
});
