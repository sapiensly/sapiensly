<?php

namespace App\Services\Builder\Integrations;

use App\Enums\IntegrationAuthType;
use App\Enums\Visibility;
use App\Facades\TenantCache;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\Auth\AuthStrategyFactory;
use App\Services\Integrations\IntegrationService;
use App\Services\Integrations\OAuth2\OAuth2DiscoveryService;
use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;
use App\Services\Integrations\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;

/**
 * Builder power #1 — the engine behind the builder's "create an integration in
 * conversation" tools. Provider-agnostic: it composes the existing integration
 * substrate (OAuth2 discovery, IntegrationService, the auth strategies, the SSRF
 * guard) so the builder can author ANY API connection without provider-specific
 * code. See docs/app-builder-create-integration-contract.md.
 */
class IntegrationAuthoring
{
    /** Discovered configs are stashed here (may carry a secret) so they never
     *  round-trip through the LLM; create() pulls them by cache_key. */
    private const DISCOVERY_TTL_MINUTES = 15;

    public function __construct(
        private readonly OAuth2DiscoveryService $discovery,
        private readonly IntegrationService $integrations,
        private readonly AuthStrategyFactory $authFactory,
        private readonly OAuth2TokenRefresher $refresher,
        private readonly SsrfGuard $ssrf,
    ) {}

    /**
     * Probe a URL for OAuth2 metadata (and, if supported, dynamically register a
     * client). Returns a **secret-free** summary for the LLM plus a cache_key the
     * create step uses to recover the full config server-side.
     *
     * @return array<string, mixed>
     */
    public function discover(string $url): array
    {
        try {
            $config = $this->discovery->autoConfigure($url, route('integrations.oauth2.callback'));
        } catch (\Throwable $e) {
            return ['discoverable' => false, 'reason' => $e->getMessage()];
        }

        $cacheKey = 'integration-discovery:'.sha1($url);
        TenantCache::put($cacheKey, $config, self::DISCOVERY_TTL_MINUTES * 60);

        $auth = $config['auth_config'] ?? [];

        return [
            'discoverable' => true,
            'cache_key' => $cacheKey,
            'base_url' => $config['base_url'] ?? null,
            'auth_type' => $config['auth_type'] ?? null,
            'authorize_url' => $auth['authorize_url'] ?? null,
            'token_url' => $auth['token_url'] ?? null,
            'scope' => $auth['scope'] ?? null,
            'dynamically_registered' => $config['dynamically_registered'] ?? false,
        ];
    }

    /**
     * Create a DRAFT (unauthorized) integration for the user's tenant. Secrets are
     * never accepted from the caller (the LLM): for a discovered OAuth2 API the
     * stashed config (by cache_key) supplies them; for key/bearer the secret is
     * captured later through a secure field, not here.
     *
     * @param  array<string, mixed>  $spec  name, base_url, auth_type, cache_key?, description?
     */
    public function createDraft(User $user, array $spec): Integration
    {
        $authType = (string) ($spec['auth_type'] ?? IntegrationAuthType::None->value);
        $authConfig = [];
        $baseUrl = (string) ($spec['base_url'] ?? '');

        if (! empty($spec['cache_key'])) {
            $discovered = TenantCache::get($spec['cache_key']);
            if (is_array($discovered)) {
                $authType = (string) ($discovered['auth_type'] ?? $authType);
                $authConfig = (array) ($discovered['auth_config'] ?? []);
                $baseUrl = $baseUrl !== '' ? $baseUrl : (string) ($discovered['base_url'] ?? '');
            }
        }

        return $this->integrations->create($user, [
            'name' => (string) ($spec['name'] ?? 'Integration'),
            'base_url' => $baseUrl,
            'auth_type' => $authType,
            'auth_config' => $authConfig,
            'description' => $spec['description'] ?? null,
            'visibility' => $user->organization_id !== null
                ? Visibility::Organization->value
                : Visibility::Private->value,
            'status' => 'draft',
        ]);
    }

    /**
     * Fire one real request against the integration to verify it works. Refreshes
     * an OAuth token if needed, applies the integration's auth strategy generically
     * (works for every auth type), and is SSRF-guarded.
     *
     * @return array{ok: bool, status?: int, sample?: string, error?: string}
     */
    public function test(Integration $integration, ?string $path = null): array
    {
        $authType = $integration->auth_type;
        if ($authType instanceof IntegrationAuthType && $authType->isOAuth2()) {
            $integration = $this->refresher->refreshIfNeeded($integration);
        }

        $applied = $this->authFactory->make($integration->auth_type)->apply($integration->auth_config ?? []);
        $url = rtrim((string) $integration->base_url, '/').'/'.ltrim((string) $path, '/');

        try {
            $this->ssrf->assertHostAllowed($url);
            $response = Http::withHeaders($applied['headers'] ?? [])
                ->timeout(10)
                ->get($url, $applied['query'] ?? []);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'sample' => mb_substr($response->body(), 0, 500),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
