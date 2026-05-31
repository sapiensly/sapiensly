<?php

namespace App\Services\Integrations\OAuth2;

use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Refreshes an OAuth2 access token. Cache-locked so concurrent requests for
 * the same integration don't fire two refresh calls (which would rotate and
 * invalidate each other on many providers).
 */
class OAuth2TokenRefresher
{
    private const SKEW_SECONDS = 30;

    private const LOCK_TTL = 10;

    public function needsRefresh(Integration $integration): bool
    {
        return $this->tokensNeedRefresh($integration->auth_config ?? []);
    }

    /**
     * Whether a standalone token bag (e.g. a per-user store) is stale.
     *
     * @param  array<string, mixed>  $tokens
     */
    public function tokensNeedRefresh(array $tokens): bool
    {
        $expiresAt = (int) ($tokens['expires_at'] ?? 0);

        if ($expiresAt === 0) {
            return empty($tokens['access_token']);
        }

        return ($expiresAt - self::SKEW_SECONDS) <= time();
    }

    /**
     * Refresh a per-user token bag using the integration's client config.
     * Returns the merged token bag (caller persists it). A no-op when the
     * access token is still valid.
     *
     * @param  array<string, mixed>  $clientConfig  authorize/token URLs + client_id/secret
     * @param  array<string, mixed>  $tokens  access_token/refresh_token/expires_at
     * @return array<string, mixed>
     */
    public function refreshTokens(array $clientConfig, array $tokens): array
    {
        if (! $this->tokensNeedRefresh($tokens)) {
            return $tokens;
        }

        if (empty($tokens['refresh_token'])) {
            throw new \RuntimeException('Authorization expired and no refresh token is available — re-authorize this tool.');
        }

        // The refresh exchange needs the client credentials from the
        // integration plus the user's refresh token.
        $cfg = array_merge($clientConfig, ['refresh_token' => $tokens['refresh_token']]);
        $fresh = $this->requestWithRefreshToken($cfg);

        return array_merge($tokens, $fresh);
    }

    public function refreshIfNeeded(Integration $integration): Integration
    {
        if (! $this->needsRefresh($integration)) {
            return $integration;
        }

        $lock = Cache::lock("oauth2-refresh-{$integration->id}", self::LOCK_TTL);

        return $lock->block(self::LOCK_TTL, function () use ($integration) {
            $integration->refresh();
            if (! $this->needsRefresh($integration)) {
                return $integration;
            }

            $cfg = $integration->auth_config ?? [];

            // Use the refresh_token when present (AuthCode flow); else fall
            // back to client_credentials flow which re-requests a fresh token.
            if (! empty($cfg['refresh_token'])) {
                $tokens = $this->requestWithRefreshToken($cfg);
                $cfg = array_merge($cfg, $tokens);
                $integration->update(['auth_config' => $cfg]);
            } else {
                $tokens = $this->requestWithClientCredentials($cfg);
                $cfg = array_merge($cfg, $tokens);
                $integration->update(['auth_config' => $cfg]);
            }

            return $integration->fresh();
        });
    }

    /**
     * @return array{access_token: string, expires_at: int, refresh_token?: string}
     */
    public function requestWithClientCredentials(array $cfg): array
    {
        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => $cfg['client_id'] ?? '',
            'client_secret' => $cfg['client_secret'] ?? '',
        ];
        if (! empty($cfg['scope'])) {
            $payload['scope'] = $cfg['scope'];
        }
        if (! empty($cfg['audience'])) {
            $payload['audience'] = $cfg['audience'];
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post($cfg['token_url'] ?? '', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('OAuth2 token request failed: '.$response->status().' '.$response->body());
        }

        return $this->normalizeTokenResponse($response->json() ?? []);
    }

    /**
     * @return array{access_token: string, expires_at: int, refresh_token?: string}
     */
    public function requestWithAuthorizationCode(array $cfg, string $code, ?string $codeVerifier = null): array
    {
        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => $cfg['client_id'] ?? '',
            'code' => $code,
            'redirect_uri' => $cfg['redirect_uri'] ?? '',
        ];
        // Public PKCE clients have no secret — sending an empty one makes
        // some providers reject the exchange. Only include it when present.
        if (! empty($cfg['client_secret'])) {
            $payload['client_secret'] = $cfg['client_secret'];
        }
        if ($codeVerifier !== null) {
            $payload['code_verifier'] = $codeVerifier;
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post($cfg['token_url'] ?? '', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('OAuth2 code exchange failed: '.$response->status().' '.$response->body());
        }

        return $this->normalizeTokenResponse($response->json() ?? []);
    }

    /**
     * @return array{access_token: string, expires_at: int, refresh_token?: string}
     */
    private function requestWithRefreshToken(array $cfg): array
    {
        $payload = [
            'grant_type' => 'refresh_token',
            'client_id' => $cfg['client_id'] ?? '',
            'refresh_token' => $cfg['refresh_token'] ?? '',
        ];
        if (! empty($cfg['client_secret'])) {
            $payload['client_secret'] = $cfg['client_secret'];
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post($cfg['token_url'] ?? '', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('OAuth2 refresh failed: '.$response->status().' '.$response->body());
        }

        $tokens = $this->normalizeTokenResponse($response->json() ?? []);

        // Some providers rotate refresh tokens — preserve new if present,
        // else fall back to the previous one by leaving the field alone.
        if (empty($tokens['refresh_token'])) {
            unset($tokens['refresh_token']);
        }

        return $tokens;
    }

    /**
     * @return array{access_token: string, expires_at: int, refresh_token?: string}
     */
    private function normalizeTokenResponse(array $payload): array
    {
        $accessToken = (string) ($payload['access_token'] ?? '');
        if ($accessToken === '') {
            throw new \RuntimeException('OAuth2 response did not contain an access_token.');
        }

        $expiresIn = (int) ($payload['expires_in'] ?? 3600);

        return [
            'access_token' => $accessToken,
            'expires_at' => time() + $expiresIn,
            'refresh_token' => $payload['refresh_token'] ?? null,
        ];
    }
}
