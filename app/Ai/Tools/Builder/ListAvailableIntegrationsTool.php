<?php

namespace App\Ai\Tools\Builder;

use App\Enums\IntegrationAuthType;
use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\User;
use App\Services\Connected\IntegrationCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Lists the tenant's configured integrations and whether the current user is
 * authorized to call each. Call this before composing a connector.call (to find
 * what already exists) or before provisioning a new connection.
 */
class ListAvailableIntegrationsTool implements Tool
{
    public function __construct(
        private User $user,
        private ?IntegrationCatalog $catalog = null,
    ) {}

    public function name(): string
    {
        return 'list_available_integrations';
    }

    public function description(): string
    {
        return 'List the tenant\'s configured integrations (external connections) and, per integration, whether you are authorized to call it. For an authorized MCP connection it ALSO returns its catalog: `mcp_tools` (each tool\'s name, description, required args + bounds) and `known_shapes` (row fields of tools already read) — so you can usually call add_connected_object directly, with NO sample_mcp_tool round-trips. Returns {integrations: [{id, name, base_url, auth_type, is_mcp, status, authorized, mcp_tools?, known_shapes?}]}.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $integrations = Integration::query()
            ->forAccountContext($this->user)
            ->orderBy('name')
            ->get();

        $authorizedTokenIds = IntegrationUserToken::query()
            ->where('user_id', $this->user->id)
            ->whereIn('integration_id', $integrations->pluck('id'))
            ->get()
            ->filter(fn (IntegrationUserToken $token): bool => $token->isAuthorized())
            ->pluck('integration_id')
            ->all();

        $payload = $integrations->map(function (Integration $integration) use ($authorizedTokenIds): array {
            $authorized = $this->isAuthorized($integration, $authorizedTokenIds);

            $row = [
                'id' => $integration->id,
                'name' => $integration->name,
                'base_url' => $integration->base_url,
                'auth_type' => $integration->auth_type instanceof IntegrationAuthType
                    ? $integration->auth_type->value
                    : $integration->auth_type,
                'is_mcp' => (bool) $integration->is_mcp,
                'status' => $integration->status,
                'authorized' => $authorized,
            ];

            // Attach the MCP catalog (tools + previously observed row shapes) so
            // the model can pick a tool and call add_connected_object with ZERO
            // discovery rounds. Best-effort: an unreachable server degrades to
            // the plain listing, never an error.
            if ($integration->is_mcp && $authorized && $this->catalog !== null) {
                try {
                    $row['mcp_tools'] = array_map(
                        fn (array $tool): array => collect($tool)->except('input_schema')->all(),
                        $this->catalog->tools($integration, $this->user),
                    );
                } catch (\Throwable $e) {
                    $row['mcp_tools_error'] = 'Could not list this server\'s tools right now: '.$e->getMessage();
                }
                $shapes = $this->catalog->knownShapes($integration);
                if ($shapes !== []) {
                    $row['known_shapes'] = $shapes;
                }
            }

            return $row;
        })->all();

        return json_encode(['integrations' => $payload], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $authorizedTokenIds
     */
    private function isAuthorized(Integration $integration, array $authorizedTokenIds): bool
    {
        $authType = $integration->auth_type;

        if ($authType === IntegrationAuthType::None) {
            return true;
        }

        // Authorization-code OAuth2 is per-user: needs a token the user granted.
        if ($authType === IntegrationAuthType::OAuth2AuthorizationCode) {
            return in_array($integration->id, $authorizedTokenIds, true);
        }

        // Org-level credential types (bearer, api_key, basic, client-credentials)
        // are authorized once the connection is out of draft.
        return $integration->status !== 'draft';
    }
}
