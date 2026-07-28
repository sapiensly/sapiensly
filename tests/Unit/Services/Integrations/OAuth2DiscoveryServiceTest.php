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

it('follows the resource_metadata URL advertised on the 401 challenge', function () {
    // The shape real deployments use (api.anthropic.com/v1/design/mcp): the
    // metadata lives at a path SUFFIX the RFC 8414 prefix candidates never hit,
    // and is only discoverable from the WWW-Authenticate header.
    Http::fake([
        'https://api.example.invalid/v1/design/mcp' => Http::response('unauthorized', 401, [
            'WWW-Authenticate' => 'Bearer resource_metadata="https://api.example.invalid/v1/design/.well-known/oauth-protected-resource", scope="user:design:read user:design:write"',
        ]),
        'https://api.example.invalid/.well-known/*' => Http::response([], 404),
        'https://api.example.invalid/v1/design/.well-known/oauth-protected-resource' => Http::response([
            'resource' => 'https://api.example.invalid/v1/design/mcp',
            'authorization_servers' => ['https://auth.example.invalid'],
            'scopes_supported' => ['user:design:read', 'user:design:write'],
        ]),
        'https://auth.example.invalid/.well-known/oauth-authorization-server' => Http::response([
            'issuer' => 'https://auth.example.invalid',
            'authorization_endpoint' => 'https://auth.example.invalid/authorize',
            'token_endpoint' => 'https://auth.example.invalid/token',
        ]),
    ]);

    $result = $this->service->autoConfigure('https://api.example.invalid/v1/design/mcp', $this->redirectUri);

    expect($result['is_mcp'])->toBeTrue()
        ->and($result['issuer'])->toBe('https://auth.example.invalid')
        ->and($result['auth_config']['scope'])->toBe('user:design:read user:design:write');
});

it('ignores a resource_metadata URL pointing off the resource origin', function () {
    Http::fake([
        'https://mcp.example.invalid' => Http::response('unauthorized', 401, [
            'WWW-Authenticate' => 'Bearer resource_metadata="https://attacker.example.invalid/.well-known/oauth-protected-resource"',
        ]),
        'https://attacker.example.invalid/*' => Http::response([
            'authorization_servers' => ['https://attacker.example.invalid'],
        ]),
        'https://mcp.example.invalid/.well-known/oauth-protected-resource' => Http::response([], 404),
        'https://mcp.example.invalid/.well-known/oauth-authorization-server' => Http::response([
            'authorization_endpoint' => 'https://mcp.example.invalid/authorize',
            'token_endpoint' => 'https://mcp.example.invalid/token',
        ]),
    ]);

    $result = $this->service->autoConfigure('https://mcp.example.invalid', $this->redirectUri);

    expect($result['issuer'])->toBe('https://mcp.example.invalid')
        ->and($result['is_mcp'])->toBeFalse();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'attacker.example.invalid'));
});

it('rejects a shared hosts catch-all metadata whose issuer does not match', function () {
    // api.anthropic.com serves one document at its origin whose issuer is
    // ".../mcp/gdrive". Accepting it would point every other MCP server on that
    // host at the wrong authorization server, with the wrong scopes.
    Http::fake([
        'https://api.example.invalid/v1/design/mcp' => Http::response([], 404),
        'https://api.example.invalid/.well-known/oauth-protected-resource*' => Http::response([], 404),
        'https://api.example.invalid/.well-known/oauth-authorization-server' => Http::response([
            'issuer' => 'https://api.example.invalid/mcp/gdrive',
            'authorization_endpoint' => 'https://api.example.invalid/authorize',
            'token_endpoint' => 'https://api.example.invalid/token',
        ]),
        'https://api.example.invalid/.well-known/openid-configuration' => Http::response([], 404),
    ]);

    $this->service->autoConfigure('https://api.example.invalid/v1/design/mcp', $this->redirectUri);
})->throws(RuntimeException::class, 'No OAuth 2.0 metadata found');

it('falls back to the challenge scope when the resource declares no scopes_supported', function () {
    Http::fake([
        'https://mcp.example.invalid' => Http::response('unauthorized', 401, [
            'WWW-Authenticate' => 'Bearer realm="mcp", resource_metadata="https://mcp.example.invalid/.well-known/oauth-protected-resource", scope="mcp:tools"',
        ]),
        'https://mcp.example.invalid/.well-known/oauth-protected-resource' => Http::response([
            'authorization_servers' => ['https://auth.example.invalid'],
        ]),
        'https://auth.example.invalid/.well-known/oauth-authorization-server' => Http::response([
            'authorization_endpoint' => 'https://auth.example.invalid/authorize',
            'token_endpoint' => 'https://auth.example.invalid/token',
        ]),
    ]);

    $result = $this->service->autoConfigure('https://mcp.example.invalid', $this->redirectUri);

    expect($result['auth_config']['scope'])->toBe('mcp:tools');
});

it('still accepts metadata that omits issuer, for servers predating RFC 8414', function () {
    Http::fake([
        'https://mcp.example.invalid/.well-known/oauth-protected-resource' => Http::response([], 404),
        'https://mcp.example.invalid/.well-known/oauth-authorization-server' => Http::response([
            'authorization_endpoint' => 'https://mcp.example.invalid/authorize',
            'token_endpoint' => 'https://mcp.example.invalid/token',
        ]),
    ]);

    $result = $this->service->autoConfigure('https://mcp.example.invalid', $this->redirectUri);

    expect($result['auth_config']['token_url'])->toBe('https://mcp.example.invalid/token');
});

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
