<?php

namespace App\Support\Integrations;

use App\Enums\IntegrationAuthType;
use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\User;

/**
 * Single source of truth for two questions the platform kept answering in three
 * places at once: "may this user call this connection?" and "does this
 * connection hold the credentials it needs?".
 *
 * The first was duplicated in ListAvailableIntegrationsTool, ResolveSourcePhase
 * and AppWorkflowController — three copies of a rule that decides whether an
 * external call is even attempted. The second did not exist anywhere, which is
 * why a draft connection could be given its API key and stay draft for ever
 * (the status is what the org-level types are judged by).
 *
 * The per-user token lookup is memoised per instance: a dashboard asks the same
 * question once per connected block.
 */
class IntegrationAuthorization
{
    /** @var array<string, bool> */
    private array $tokenMemo = [];

    /**
     * Whether $user may call $integration right now.
     *
     * Authorization-code OAuth2 is per USER — every viewer authorizes their own
     * token, so a connection one person connected is not connected for the next.
     * Every other type is an org-level credential: authorized once the
     * connection leaves draft.
     */
    public function authorizedFor(Integration $integration, ?User $user): bool
    {
        $authType = $this->authTypeOf($integration);

        if ($authType === IntegrationAuthType::None) {
            return true;
        }

        if ($authType === IntegrationAuthType::OAuth2AuthorizationCode) {
            return $user !== null && $this->hasUserToken($integration, $user);
        }

        return $integration->status !== 'draft';
    }

    /**
     * Whether this connection asks each user to connect their own account
     * (rather than running on one shared org credential). Drives the "connect
     * your account" affordance: telling a viewer to go and authorize is only
     * ever true for this type.
     */
    public function requiresPerUserConsent(Integration $integration): bool
    {
        return $this->authTypeOf($integration) === IntegrationAuthType::OAuth2AuthorizationCode;
    }

    /**
     * Whether the connection now holds every credential its auth type needs to
     * make a call. This is what promotes a draft to active — a draft with its
     * key entered is a working connection that merely says otherwise.
     *
     * For authorization-code OAuth2 the answer is about the CLIENT config
     * (authorize/token url + client id, and the secret unless PKCE): the
     * per-user access token is a separate, per-viewer fact.
     *
     * @param  array<string, mixed>|null  $authConfig  defaults to the integration's own
     */
    public function hasUsableCredentials(Integration $integration, ?array $authConfig = null): bool
    {
        $config = $authConfig ?? $integration->auth_config ?? [];

        $filled = fn (string $key): bool => ! in_array($config[$key] ?? null, [null, '', []], true);

        return match ($this->authTypeOf($integration)) {
            IntegrationAuthType::None => true,
            IntegrationAuthType::ApiKey => $filled('name') && $filled('value'),
            IntegrationAuthType::BearerToken => $filled('token'),
            IntegrationAuthType::BasicAuth => $filled('username') && $filled('password'),
            IntegrationAuthType::CustomHeaders => $filled('headers'),
            IntegrationAuthType::OAuth2ClientCredentials => $filled('token_url') && $filled('client_id') && $filled('client_secret'),
            IntegrationAuthType::OAuth2AuthorizationCode => $filled('authorize_url')
                && $filled('token_url')
                && $filled('client_id')
                && (! empty($config['pkce']) || $filled('client_secret')),
            null => false,
        };
    }

    /**
     * The `auth_config` key a single user-supplied secret belongs under, for the
     * types whose whole credential IS one secret. Null when the type needs more
     * than one field (basic auth, OAuth) and so cannot be captured inline.
     */
    public function secretFieldFor(Integration $integration): ?string
    {
        return match ($this->authTypeOf($integration)) {
            IntegrationAuthType::ApiKey => 'value',
            IntegrationAuthType::BearerToken => 'token',
            default => null,
        };
    }

    private function hasUserToken(Integration $integration, User $user): bool
    {
        $key = $integration->id.':'.$user->id;

        return $this->tokenMemo[$key] ??= IntegrationUserToken::query()
            ->where('integration_id', $integration->id)
            ->where('user_id', $user->id)
            ->get()
            ->contains(fn (IntegrationUserToken $token): bool => $token->isAuthorized());
    }

    private function authTypeOf(Integration $integration): ?IntegrationAuthType
    {
        return $integration->auth_type instanceof IntegrationAuthType
            ? $integration->auth_type
            : IntegrationAuthType::tryFrom((string) $integration->auth_type);
    }
}
