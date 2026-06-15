<?php

namespace App\Ai\Tools\Builder;

use App\Services\Builder\Integrations\IntegrationAuthoring;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Builder power #1. Probe an API URL for OAuth2 metadata (and dynamic client
 * registration where supported). Read-only; returns a SECRET-FREE summary plus a
 * `cache_key` to hand to create_integration. Provider-agnostic.
 */
class DiscoverIntegrationTool implements Tool
{
    public function __construct(private IntegrationAuthoring $authoring) {}

    public function name(): string
    {
        return 'discover_integration';
    }

    public function description(): string
    {
        return <<<'DESC'
Probe an external API for OAuth2 configuration before connecting it. Pass the API's
base URL or docs/issuer URL. Returns {discoverable, base_url, auth_type,
authorize_url, token_url, scope, cache_key} for an OAuth2 API — pass cache_key to
create_integration so secrets never pass through you. If {discoverable:false}, the
API is not OAuth2-auto-configurable: ask the user for its base_url and auth kind
(api_key / bearer) and call create_integration directly. Never returns secrets.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description("The API's base URL or issuer/docs URL.")->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $url = (string) ($request->all()['url'] ?? '');
        if ($url === '') {
            return json_encode(['discoverable' => false, 'reason' => 'A url is required.'], JSON_THROW_ON_ERROR);
        }

        return json_encode($this->authoring->discover($url), JSON_THROW_ON_ERROR);
    }
}
