<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationAuthType;
use App\Models\Integration;
use App\Services\Integrations\Auth\AuthStrategyFactory;
use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;
use App\Services\Integrations\Support\SsrfGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Generic authenticated, SSRF-guarded, token-refreshing HTTP call THROUGH an
 * integration. The shared bridge both the builder's connection verification
 * (power #1) and connected objects (power #2) use to reach an external system —
 * provider-agnostic, no per-provider code. Applies the integration's auth
 * strategy (any auth type) and refreshes OAuth tokens on demand.
 */
class IntegrationCaller
{
    private const TIMEOUT = 10;

    public function __construct(
        private readonly AuthStrategyFactory $authFactory,
        private readonly OAuth2TokenRefresher $refresher,
        private readonly SsrfGuard $ssrf,
    ) {}

    /**
     * @param  array<string, mixed>  $options  query?, json?
     */
    public function send(Integration $integration, string $method, string $path, array $options = []): Response
    {
        $authType = $integration->auth_type;
        if ($authType instanceof IntegrationAuthType && $authType->isOAuth2()) {
            $integration = $this->refresher->refreshIfNeeded($integration);
        }

        $applied = $this->authFactory->make($integration->auth_type)->apply($integration->auth_config ?? []);
        $url = rtrim((string) $integration->base_url, '/').'/'.ltrim($path, '/');
        $this->ssrf->assertHostAllowed($url);

        $guzzle = ['query' => array_merge($applied['query'] ?? [], (array) ($options['query'] ?? []))];
        if (array_key_exists('json', $options)) {
            $guzzle['json'] = $options['json'];
        }

        return Http::withHeaders($applied['headers'] ?? [])
            ->timeout(self::TIMEOUT)
            ->send(strtoupper($method), $url, $guzzle);
    }
}
