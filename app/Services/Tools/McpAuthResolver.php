<?php

namespace App\Services\Tools;

use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\User;
use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;

/**
 * Resolves the HTTP headers an MCP tool must send to authenticate with its
 * server. Static schemes (bearer/api_key/basic) are built from the tool's own
 * encrypted auth_config; the `oauth2` scheme uses the *current user's* token
 * for the linked integration (per-user authorization), refreshing it on the
 * fly. The integration only holds the shared client configuration.
 */
class McpAuthResolver
{
    public function __construct(
        private readonly OAuth2TokenRefresher $tokenRefresher,
    ) {}

    /**
     * Build the request headers for an MCP connection from a decrypted config.
     *
     * @param  array<string, mixed>  $config  Decrypted MCP tool config.
     * @param  User|null  $user  The user the request runs as (required for oauth2).
     * @return array<string, string>
     *
     * @throws \RuntimeException When an oauth2 link is missing or unauthorized.
     */
    public function resolveHeaders(array $config, ?User $user = null): array
    {
        $authType = $config['auth_type'] ?? 'none';
        $authConfig = $config['auth_config'] ?? [];

        return match ($authType) {
            'bearer' => $this->headerFor('Authorization', 'Bearer '.($authConfig['token'] ?? '')),
            'api_key' => $this->apiKeyHeaders($authConfig),
            'basic' => $this->basicHeaders($authConfig),
            'oauth2' => $this->oauth2Headers($config, $user),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $authConfig
     * @return array<string, string>
     */
    private function apiKeyHeaders(array $authConfig): array
    {
        $location = $authConfig['location'] ?? 'header';
        if ($location !== 'header') {
            return [];
        }

        $name = (string) ($authConfig['name'] ?? 'X-API-Key');
        $value = (string) ($authConfig['value'] ?? '');

        return $value === '' ? [] : [$name => $value];
    }

    /**
     * @param  array<string, mixed>  $authConfig
     * @return array<string, string>
     */
    private function basicHeaders(array $authConfig): array
    {
        $username = (string) ($authConfig['username'] ?? '');
        $password = (string) ($authConfig['password'] ?? '');

        if ($username === '' && $password === '') {
            return [];
        }

        return ['Authorization' => 'Basic '.base64_encode($username.':'.$password)];
    }

    /**
     * Use the current user's token for the linked integration, refreshing it
     * on the fly. The integration supplies the client config used to refresh.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function oauth2Headers(array $config, ?User $user): array
    {
        $integrationId = $config['integration_id'] ?? null;
        if (empty($integrationId)) {
            throw new \RuntimeException('MCP tool uses OAuth 2.0 but no integration is linked.');
        }

        if (! $user instanceof User) {
            throw new \RuntimeException('OAuth 2.0 MCP tools require a user context to resolve the token.');
        }

        $integration = Integration::find($integrationId);
        if (! $integration instanceof Integration) {
            throw new \RuntimeException('Linked OAuth 2.0 integration not found.');
        }

        $userToken = IntegrationUserToken::query()
            ->where('user_id', $user->id)
            ->where('integration_id', $integration->id)
            ->first();

        if (! $userToken instanceof IntegrationUserToken || ! $userToken->isAuthorized()) {
            throw new \RuntimeException('You have not authorized this tool yet — open the tool and click Authorize.');
        }

        $tokens = $userToken->auth_config ?? [];

        if ($this->tokenRefresher->tokensNeedRefresh($tokens)) {
            $tokens = $this->tokenRefresher->refreshTokens($integration->auth_config ?? [], $tokens);
            $userToken->update(['auth_config' => $tokens]);
        }

        return ['Authorization' => 'Bearer '.$tokens['access_token']];
    }

    /**
     * @return array<string, string>
     */
    private function headerFor(string $name, string $value): array
    {
        return trim($value) === '' || str_ends_with(trim($value), 'Bearer') ? [] : [$name => $value];
    }
}
