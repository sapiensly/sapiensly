<?php

namespace App\Services\Integrations\Auth;

/**
 * Applies the cached OAuth2 client-credentials access token as a Bearer
 * header. Token acquisition and refresh happen outside this strategy — the
 * request executor pre-populates `auth_config.access_token` via the matching
 * OAuth2 flow service before calling apply().
 */
class OAuth2ClientCredentialsStrategy implements AuthStrategy
{
    public function apply(array $authConfig): array
    {
        $accessToken = (string) ($authConfig['access_token'] ?? '');

        if ($accessToken === '') {
            return ['headers' => [], 'query' => []];
        }

        return [
            'headers' => ['Authorization' => 'Bearer '.$accessToken],
            'query' => [],
        ];
    }
}
