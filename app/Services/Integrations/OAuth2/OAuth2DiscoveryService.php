<?php

namespace App\Services\Integrations\OAuth2;

use App\Services\Integrations\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Turns a single URL into a complete OAuth 2.0 Authorization Code config by
 * following the discovery chain MCP servers expose:
 *
 *   1. Protected Resource Metadata (RFC 9728) — find the authorization server.
 *   2. Authorization Server Metadata (RFC 8414 / OpenID Discovery) — find the
 *      authorize, token and (optional) dynamic registration endpoints.
 *   3. Dynamic Client Registration (RFC 7591) — mint a client_id/secret when
 *      the server supports it, so the user never copies credentials by hand.
 *
 * Servers that don't support dynamic registration still get their endpoints
 * filled in; the caller then asks the user for client_id/client_secret.
 */
class OAuth2DiscoveryService
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    /**
     * @return array{
     *     base_url: string,
     *     name: string,
     *     auth_type: string,
     *     auth_config: array<string, mixed>,
     *     dynamically_registered: bool,
     *     requires_client_credentials: bool,
     *     issuer: string,
     * }
     *
     * @throws \RuntimeException When the authorization server cannot be discovered.
     */
    public function autoConfigure(string $url, string $redirectUri, ?string $clientName = null): array
    {
        $resourceUrl = $this->normalize($url);
        $discovered = $this->discoverAuthorizationServer($resourceUrl);
        $issuer = $discovered['issuer'];
        $metadata = $this->fetchAuthorizationServerMetadata($issuer);

        $authorizeUrl = (string) ($metadata['authorization_endpoint'] ?? '');
        $tokenUrl = (string) ($metadata['token_endpoint'] ?? '');

        if ($authorizeUrl === '' || $tokenUrl === '') {
            throw new \RuntimeException(__('Could not find the authorize and token endpoints for this server.'));
        }

        $scope = $this->defaultScope($metadata);
        $pkce = $this->supportsPkce($metadata);

        $authConfig = [
            'authorize_url' => $authorizeUrl,
            'token_url' => $tokenUrl,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'pkce' => $pkce,
            'client_id' => '',
            'client_secret' => '',
        ];

        $registrationUrl = (string) ($metadata['registration_endpoint'] ?? '');
        $dynamicallyRegistered = false;

        if ($registrationUrl !== '') {
            $registration = $this->registerClient($registrationUrl, $redirectUri, $scope, $clientName);
            if ($registration !== null) {
                $authConfig['client_id'] = (string) ($registration['client_id'] ?? '');
                $authConfig['client_secret'] = (string) ($registration['client_secret'] ?? '');
                $authConfig['registration_url'] = $registrationUrl;
                $dynamicallyRegistered = $authConfig['client_id'] !== '';
            }
        }

        return [
            'base_url' => $resourceUrl,
            'name' => $clientName ?: $this->nameFromUrl($resourceUrl),
            'auth_type' => 'oauth2_auth_code',
            'auth_config' => $authConfig,
            'dynamically_registered' => $dynamicallyRegistered,
            'requires_client_credentials' => ! $dynamicallyRegistered,
            'issuer' => $issuer,
            // A published Protected Resource Metadata document is the MCP
            // resource-server discovery pattern — treat that as "this is MCP".
            'is_mcp' => $discovered['is_mcp'],
        ];
    }

    /**
     * Resolve the authorization server issuer for a resource URL. Falls back
     * to treating the resource origin as the issuer when no Protected Resource
     * Metadata document is published.
     *
     * @return array{issuer: string, is_mcp: bool}
     */
    private function discoverAuthorizationServer(string $resourceUrl): array
    {
        foreach ($this->protectedResourceCandidates($resourceUrl) as $candidate) {
            $metadata = $this->fetchJson($candidate);
            $servers = $metadata['authorization_servers'] ?? null;
            if (is_array($servers) && ! empty($servers[0])) {
                return ['issuer' => rtrim((string) $servers[0], '/'), 'is_mcp' => true];
            }
        }

        return ['issuer' => $this->origin($resourceUrl), 'is_mcp' => false];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When no metadata document can be read.
     */
    private function fetchAuthorizationServerMetadata(string $issuer): array
    {
        foreach ($this->metadataCandidates($issuer) as $candidate) {
            $metadata = $this->fetchJson($candidate);
            if (is_array($metadata) && ! empty($metadata['token_endpoint'])) {
                return $metadata;
            }
        }

        throw new \RuntimeException(__('No OAuth 2.0 metadata found at this URL. The server may not support discovery — enter the endpoints manually.'));
    }

    /**
     * Perform RFC 7591 Dynamic Client Registration.
     *
     * @return array<string, mixed>|null Null when the server rejects registration.
     */
    private function registerClient(string $registrationUrl, string $redirectUri, string $scope, ?string $clientName): ?array
    {
        try {
            $this->ssrfGuard->assertHostAllowed($registrationUrl);
        } catch (\RuntimeException) {
            return null;
        }

        // MCP clients are public clients that authenticate with PKCE, so ask
        // for a tokenless registration. Servers that insist on a confidential
        // client will still return a client_secret, which we store and use.
        $payload = [
            'client_name' => $clientName ?: config('app.name', 'Sapiensly'),
            'redirect_uris' => [$redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
        if ($scope !== '') {
            $payload['scope'] = $scope;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post($registrationUrl, $payload);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) && ! empty($data['client_id']) ? $data : null;
    }

    /**
     * GET a URL as JSON, guarded against SSRF. Returns null on any failure so
     * callers can fall through to the next discovery candidate.
     *
     * @return array<string, mixed>|null
     */
    private function fetchJson(string $url): ?array
    {
        try {
            $this->ssrfGuard->assertHostAllowed($url);
        } catch (\RuntimeException) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<int, string>
     */
    private function protectedResourceCandidates(string $resourceUrl): array
    {
        $origin = $this->origin($resourceUrl);
        $path = trim((string) parse_url($resourceUrl, PHP_URL_PATH), '/');

        $candidates = [$origin.'/.well-known/oauth-protected-resource'];
        if ($path !== '') {
            $candidates[] = $origin.'/.well-known/oauth-protected-resource/'.$path;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array<int, string>
     */
    private function metadataCandidates(string $issuer): array
    {
        $issuer = rtrim($issuer, '/');
        $origin = $this->origin($issuer);
        $path = trim((string) parse_url($issuer, PHP_URL_PATH), '/');

        $candidates = [
            $issuer.'/.well-known/oauth-authorization-server',
            $issuer.'/.well-known/openid-configuration',
        ];

        // RFC 8414 inserts the well-known segment between host and path.
        if ($path !== '') {
            $candidates[] = $origin.'/.well-known/oauth-authorization-server/'.$path;
            $candidates[] = $origin.'/.well-known/openid-configuration/'.$path;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function defaultScope(array $metadata): string
    {
        $scopes = $metadata['scopes_supported'] ?? null;

        return is_array($scopes) ? implode(' ', array_map('strval', $scopes)) : '';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function supportsPkce(array $metadata): bool
    {
        $methods = $metadata['code_challenge_methods_supported'] ?? null;
        if (is_array($methods)) {
            return in_array('S256', $methods, true);
        }

        // MCP authorization requires PKCE; default to on when unspecified.
        return true;
    }

    private function normalize(string $url): string
    {
        $url = trim($url);
        if (! Str::startsWith($url, ['http://', 'https://'])) {
            $url = 'https://'.$url;
        }

        return rtrim($url, '/');
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    private function nameFromUrl(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $host !== '' ? $host : 'MCP Server';
    }
}
