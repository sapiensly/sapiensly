<?php

use App\Services\Integrations\OAuth2\OAuth2DiscoveryService;
use App\Services\Integrations\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->service = new OAuth2DiscoveryService(new SsrfGuard);
    $this->redirectUri = 'https://app.test/oauth/integrations/callback';
});

it('discovers endpoints and dynamically registers a client (full MCP chain)', function () {
    Http::fake([
        '*/.well-known/oauth-protected-resource' => Http::response([
            'authorization_servers' => ['https://auth.example.invalid'],
        ]),
        '*/.well-known/oauth-authorization-server' => Http::response([
            'authorization_endpoint' => 'https://auth.example.invalid/authorize',
            'token_endpoint' => 'https://auth.example.invalid/token',
            'registration_endpoint' => 'https://auth.example.invalid/register',
            'scopes_supported' => ['mcp:read', 'mcp:write'],
            'code_challenge_methods_supported' => ['S256'],
        ]),
        'https://auth.example.invalid/register' => Http::response([
            'client_id' => 'dyn-client-123',
            'client_secret' => 'dyn-secret-456',
        ], 201),
    ]);

    $result = $this->service->autoConfigure('https://mcp.example.invalid', $this->redirectUri);

    expect($result['dynamically_registered'])->toBeTrue()
        ->and($result['requires_client_credentials'])->toBeFalse()
        ->and($result['is_mcp'])->toBeTrue()
        ->and($result['auth_type'])->toBe('oauth2_auth_code')
        ->and($result['base_url'])->toBe('https://mcp.example.invalid')
        ->and($result['auth_config']['authorize_url'])->toBe('https://auth.example.invalid/authorize')
        ->and($result['auth_config']['token_url'])->toBe('https://auth.example.invalid/token')
        ->and($result['auth_config']['client_id'])->toBe('dyn-client-123')
        ->and($result['auth_config']['client_secret'])->toBe('dyn-secret-456')
        ->and($result['auth_config']['redirect_uri'])->toBe($this->redirectUri)
        ->and($result['auth_config']['scope'])->toBe('mcp:read mcp:write')
        ->and($result['auth_config']['pkce'])->toBeTrue();
});

it('falls back to the resource origin as issuer when no protected-resource metadata exists', function () {
    Http::fake([
        '*/.well-known/oauth-protected-resource' => Http::response([], 404),
        'https://mcp.example.invalid/.well-known/oauth-authorization-server' => Http::response([
            'authorization_endpoint' => 'https://mcp.example.invalid/authorize',
            'token_endpoint' => 'https://mcp.example.invalid/token',
            'registration_endpoint' => 'https://mcp.example.invalid/register',
        ]),
        'https://mcp.example.invalid/register' => Http::response(['client_id' => 'cid'], 201),
    ]);

    $result = $this->service->autoConfigure('https://mcp.example.invalid', $this->redirectUri);

    expect($result['issuer'])->toBe('https://mcp.example.invalid')
        ->and($result['auth_config']['client_id'])->toBe('cid')
        ->and($result['is_mcp'])->toBeFalse();
});

it('reports that client credentials are required when the server has no registration endpoint', function () {
    Http::fake([
        '*/.well-known/oauth-protected-resource' => Http::response([], 404),
        '*/.well-known/oauth-authorization-server' => Http::response([
            'authorization_endpoint' => 'https://mcp.example.invalid/authorize',
            'token_endpoint' => 'https://mcp.example.invalid/token',
        ]),
    ]);

    $result = $this->service->autoConfigure('https://mcp.example.invalid', $this->redirectUri);

    expect($result['dynamically_registered'])->toBeFalse()
        ->and($result['requires_client_credentials'])->toBeTrue()
        ->and($result['auth_config']['client_id'])->toBe('')
        ->and($result['auth_config']['authorize_url'])->toBe('https://mcp.example.invalid/authorize');
});

it('throws when no OAuth metadata can be discovered', function () {
    Http::fake([
        '*' => Http::response([], 404),
    ]);

    $this->service->autoConfigure('https://mcp.example.invalid', $this->redirectUri);
})->throws(RuntimeException::class, 'No OAuth 2.0 metadata found');

it('falls back to openid-configuration when oauth-authorization-server is absent', function () {
    Http::fake([
        '*/.well-known/oauth-protected-resource' => Http::response([], 404),
        '*/.well-known/oauth-authorization-server' => Http::response([], 404),
        '*/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://mcp.example.invalid/authorize',
            'token_endpoint' => 'https://mcp.example.invalid/token',
        ]),
    ]);

    $result = $this->service->autoConfigure('https://mcp.example.invalid', $this->redirectUri);

    expect($result['auth_config']['token_url'])->toBe('https://mcp.example.invalid/token');
});
