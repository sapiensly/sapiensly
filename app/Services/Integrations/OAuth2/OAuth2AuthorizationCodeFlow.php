<?php

namespace App\Services\Integrations\OAuth2;

use App\Models\Integration;
use Illuminate\Support\Str;

/**
 * Authorization Code + PKCE OAuth2 flow. Splits into two steps:
 *   1) buildAuthorizeUrl(): returns the provider URL to redirect the user to,
 *      stashing state + code_verifier in the session for the callback leg.
 *   2) handleCallback(): exchanges the returned code for tokens.
 */
class OAuth2AuthorizationCodeFlow
{
    public function __construct(
        private OAuth2TokenRefresher $refresher,
    ) {}

    /**
     * @return array{url: string, state: string, code_verifier: ?string}
     */
    public function buildAuthorizeUrl(Integration $integration): array
    {
        $cfg = $integration->auth_config ?? [];
        $state = Str::random(40);
        $codeVerifier = null;

        $params = [
            'response_type' => 'code',
            'client_id' => $cfg['client_id'] ?? '',
            'redirect_uri' => $cfg['redirect_uri'] ?? '',
            'scope' => $cfg['scope'] ?? '',
            'state' => $state,
        ];

        if (! empty($cfg['pkce'])) {
            $codeVerifier = Str::random(64);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            $params['code_challenge'] = $challenge;
            $params['code_challenge_method'] = 'S256';
        }

        $separator = str_contains($cfg['authorize_url'] ?? '', '?') ? '&' : '?';
        $url = ($cfg['authorize_url'] ?? '').$separator.http_build_query($params);

        return [
            'url' => $url,
            'state' => $state,
            'code_verifier' => $codeVerifier,
        ];
    }

    /**
     * @throws \RuntimeException on state mismatch or token exchange failure
     */
    public function handleCallback(
        Integration $integration,
        string $code,
        string $stateFromProvider,
        string $stateExpected,
        ?string $codeVerifier,
    ): Integration {
        if (! hash_equals($stateExpected, $stateFromProvider)) {
            throw new \RuntimeException('OAuth2 state mismatch — possible CSRF.');
        }

        $cfg = $integration->auth_config ?? [];
        $tokens = $this->refresher->requestWithAuthorizationCode($cfg, $code, $codeVerifier);

        $integration->update([
            'auth_config' => array_merge($cfg, $tokens),
        ]);

        return $integration->fresh();
    }
}
