<?php

namespace App\Services\Sso;

use App\Models\OrganizationSsoConnection;
use App\Services\Integrations\OAuth2\OAuth2AuthorizationCodeFlow;
use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;
use App\Services\Integrations\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Orchestrates the per-organization OIDC Authorization-Code login. Reuses the
 * integration OAuth2 toolkit for the PKCE authorize URL and the code→token
 * exchange; identity is read from the IdP userinfo endpoint over TLS (no JWT
 * signature verification — a documented hardening gap, acceptable because the
 * userinfo call is a direct server-to-server request to the discovered issuer).
 */
class OidcLoginService
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private OAuth2AuthorizationCodeFlow $flow,
        private OAuth2TokenRefresher $refresher,
        private SsrfGuard $ssrfGuard,
    ) {}

    /**
     * Resolve OIDC endpoints from an issuer's discovery document.
     *
     * @return array{authorize_url: string, token_url: string, userinfo_url: string, jwks_uri: string, scope: string, pkce: bool}
     *
     * @throws \RuntimeException when the discovery document is unreachable or incomplete.
     */
    public function discover(string $issuer): array
    {
        $url = rtrim(trim($issuer), '/').'/.well-known/openid-configuration';
        $this->ssrfGuard->assertHostAllowed($url);

        $response = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->get($url);
        $metadata = $response->successful() ? $response->json() : null;

        if (! is_array($metadata) || empty($metadata['authorization_endpoint']) || empty($metadata['token_endpoint'])) {
            throw new \RuntimeException(__('Could not read the OIDC discovery document for this issuer.'));
        }

        $scopesSupported = is_array($metadata['scopes_supported'] ?? null) ? $metadata['scopes_supported'] : [];
        $pkceMethods = is_array($metadata['code_challenge_methods_supported'] ?? null)
            ? $metadata['code_challenge_methods_supported']
            : [];

        return [
            'authorize_url' => (string) $metadata['authorization_endpoint'],
            'token_url' => (string) $metadata['token_endpoint'],
            'userinfo_url' => (string) ($metadata['userinfo_endpoint'] ?? ''),
            'jwks_uri' => (string) ($metadata['jwks_uri'] ?? ''),
            'scope' => $this->defaultScope($scopesSupported),
            'pkce' => in_array('S256', $pkceMethods, true),
        ];
    }

    /**
     * Build the authorize redirect for a connection's login leg. The caller
     * persists state/nonce/code_verifier in the session for the callback.
     *
     * @return array{url: string, state: string, nonce: string, code_verifier: ?string}
     */
    public function buildLoginRedirect(OrganizationSsoConnection $connection, string $redirectUri): array
    {
        $cfg = $this->clientConfig($connection, $redirectUri);
        $nonce = Str::random(40);
        $prepared = $this->flow->buildAuthorizeUrlFromConfig($cfg, ['nonce' => $nonce]);

        return [
            'url' => $prepared['url'],
            'state' => $prepared['state'],
            'nonce' => $nonce,
            'code_verifier' => $prepared['code_verifier'],
        ];
    }

    /**
     * Exchange the authorization code and resolve the user's verified claims
     * from the IdP userinfo endpoint.
     *
     * @return array{email: string, sub: string, name: ?string, email_verified: bool}
     *
     * @throws \RuntimeException on exchange/userinfo failure.
     */
    public function resolveIdentity(OrganizationSsoConnection $connection, string $redirectUri, string $code, ?string $codeVerifier): array
    {
        $cfg = $this->clientConfig($connection, $redirectUri);
        $tokens = $this->refresher->requestWithAuthorizationCode($cfg, $code, $codeVerifier);

        $userinfoUrl = (string) ($cfg['userinfo_url'] ?? '');
        if ($userinfoUrl === '') {
            throw new \RuntimeException(__('This SSO connection has no userinfo endpoint configured.'));
        }

        $this->ssrfGuard->assertHostAllowed($userinfoUrl);
        $response = Http::withToken($tokens['access_token'])
            ->timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->get($userinfoUrl);

        $claims = $response->successful() ? $response->json() : null;
        $email = is_array($claims) ? strtolower(trim((string) ($claims['email'] ?? ''))) : '';

        if ($email === '') {
            throw new \RuntimeException(__('The identity provider did not return an email address.'));
        }

        return [
            'email' => $email,
            'sub' => (string) ($claims['sub'] ?? ''),
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
            'email_verified' => (bool) ($claims['email_verified'] ?? false),
        ];
    }

    /**
     * Merge the stored connection config with the runtime redirect URI. The
     * redirect URI is never persisted — it is always the app's SSO callback —
     * so a moved deployment can't strand a connection on a stale URL.
     *
     * @return array<string, mixed>
     */
    private function clientConfig(OrganizationSsoConnection $connection, string $redirectUri): array
    {
        return array_merge($connection->config ?? [], [
            'client_id' => $connection->client_id,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * @param  array<int, mixed>  $scopesSupported
     */
    private function defaultScope(array $scopesSupported): string
    {
        $wanted = ['openid', 'email', 'profile'];
        $supported = array_map('strval', $scopesSupported);
        $scopes = array_values(array_filter($wanted, fn (string $s): bool => $supported === [] || in_array($s, $supported, true)));

        // openid is mandatory for OIDC even if the metadata omits scopes_supported.
        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return implode(' ', $scopes);
    }
}
