<?php

namespace App\Services\Integrations\Auth;

/**
 * Mirror of OAuth2ClientCredentialsStrategy — both consume a cached access
 * token. They differ in how the token is acquired, which is the flow
 * service's concern, not the strategy's.
 */
class OAuth2AuthorizationCodeStrategy implements AuthStrategy
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
