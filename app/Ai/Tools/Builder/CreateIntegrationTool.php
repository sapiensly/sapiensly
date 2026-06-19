<?php

namespace App\Ai\Tools\Builder;

use App\Enums\IntegrationAuthType;
use App\Models\User;
use App\Services\Builder\Integrations\IntegrationAuthoring;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Builder power #1. Create a DRAFT (unauthorized) integration for the tenant. The
 * draft is not usable until the user authorizes it — OAuth consent, or entering a
 * secret in a secure field. **Never put secrets in these arguments**: for a
 * discovered OAuth2 API pass the `cache_key` from discover_integration; for a
 * key/bearer API pass base_url + auth_type and the secret is captured separately.
 */
class CreateIntegrationTool implements Tool
{
    /**
     * Structured proposal for the last integration created this turn, surfaced
     * to the user as a provisioning card (provider, what the flow needs, the
     * read/write blast radius, and whether authorization is still required).
     *
     * @var array<string, mixed>|null
     */
    private ?array $proposal = null;

    public function __construct(
        private IntegrationAuthoring $authoring,
        private User $user,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function proposal(): ?array
    {
        return $this->proposal;
    }

    public function name(): string
    {
        return 'create_integration';
    }

    public function description(): string
    {
        return <<<'DESC'
Create a DRAFT connection (integration) for this tenant. It is created unauthorized
and unusable until the user authorizes it (OAuth consent or secret entry) — that
authorization is the human gate, so this never acts on the external system by
itself. NEVER pass secrets (tokens, client secrets, passwords) in the arguments.
For a discovered OAuth2 API, pass `cache_key` from discover_integration. For a
key/bearer API, pass `base_url` and `auth_type` (the secret is captured securely
afterwards). When provisioning for a flow, pass `reason` and the `actions` the
flow needs so the user sees what they are authorizing. Returns {ok,
integration_id, status:"draft", authorize_required}.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Human-readable name, e.g. "HubSpot".')->required(),
            'base_url' => $schema->string()->description('API base URL, e.g. https://api.hubapi.com. Optional if cache_key is given.'),
            'auth_type' => $schema->string()->description('One of: none, api_key, bearer, basic, oauth2_auth_code, oauth2_client_credentials. Optional if cache_key is given.'),
            'cache_key' => $schema->string()->description('The cache_key returned by discover_integration (OAuth2 path).'),
            'description' => $schema->string()->description('Optional one-line description.'),
            'reason' => $schema->string()->description('Why the flow needs this connection, e.g. "to post the deal summary to Slack". Shown on the provisioning card.'),
            'actions' => $schema->array()->description('The actions the flow needs from this integration, each a short label, e.g. ["Post a message"]. Shown on the provisioning card.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return json_encode(['ok' => false, 'error' => 'name is required.'], JSON_THROW_ON_ERROR);
        }

        $authType = (string) ($args['auth_type'] ?? IntegrationAuthType::None->value);
        if (empty($args['cache_key']) && IntegrationAuthType::tryFrom($authType) === null) {
            return json_encode([
                'ok' => false,
                'error' => 'Unknown auth_type. Use one of: '.implode(', ', array_map(fn ($c) => $c->value, IntegrationAuthType::cases())),
            ], JSON_THROW_ON_ERROR);
        }

        $integration = $this->authoring->createDraft($this->user, [
            'name' => $name,
            'base_url' => $args['base_url'] ?? null,
            'auth_type' => $authType,
            'cache_key' => $args['cache_key'] ?? null,
            'description' => $args['description'] ?? null,
        ]);

        $resolvedType = $integration->auth_type instanceof IntegrationAuthType
            ? $integration->auth_type
            : IntegrationAuthType::tryFrom((string) $integration->auth_type);

        $authorizeRequired = $resolvedType !== IntegrationAuthType::None;

        $actions = array_values(array_filter(
            array_map('strval', (array) ($args['actions'] ?? [])),
            fn (string $a): bool => trim($a) !== '',
        ));

        $this->proposal = [
            'integration_id' => $integration->id,
            'name' => $integration->name,
            'auth_type' => $resolvedType?->value ?? (string) $integration->auth_type,
            'authorize_required' => $authorizeRequired,
            'authorized' => ! $authorizeRequired,
            'reason' => trim((string) ($args['reason'] ?? '')),
            'actions' => $actions,
        ];

        return json_encode([
            'ok' => true,
            'integration_id' => $integration->id,
            'status' => $integration->status,
            'authorize_required' => $authorizeRequired,
            'message' => 'Draft connection created and shown to the user as a provisioning card. It must be authorized (the user connects in the provider\'s own surface) before any step that depends on it can run.',
        ], JSON_THROW_ON_ERROR);
    }
}
