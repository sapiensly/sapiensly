<?php

namespace App\Mcp\Tools\Integrations;

use App\Enums\IntegrationAuthType;
use App\Mcp\Tools\Integrations\Concerns\PresentsIntegration;
use App\Mcp\Tools\SapiensTool;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\IntegrationService;
use App\Support\Integrations\IntegrationRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create an integration (a Connection: base URL + auth that tools share). Pick a kind (http for REST/GraphQL, mcp for an MCP server, database for an external DB) and an auth_type (none, api_key, bearer, basic, oauth2_auth_code, oauth2_client_credentials); pass credentials in auth_config. See tools_reference topic=integration for the auth_config shapes. Credentials are encrypted at rest. Then create connected tools against it via create_tool with config.integration_id.')]
class CreateIntegrationTool extends SapiensTool
{
    use PresentsIntegration;

    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('create', Integration::class)) {
            return Response::error('You do not have permission to create integrations.');
        }

        $data = $request->all();
        $validator = ValidatorFactory::make($data, IntegrationRules::store($data['kind'] ?? null));
        $validator->after(function (Validator $v) use ($data) {
            IntegrationRules::validateOAuth2OnStore($v, (array) ($data['auth_config'] ?? []), $data['auth_type'] ?? null);
        });
        $validated = $validator->validate();

        $integration = app(IntegrationService::class)->create($user, $validated);

        return Response::json($this->integrationPayload($integration));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The integration (connection) name.')->required(),
            'base_url' => $schema->string()->description('Base URL for http/mcp kinds, or a DSN for database. Required.')->required(),
            'kind' => $schema->string()->enum(['http', 'mcp', 'database'])->description('http (REST/GraphQL), mcp (MCP server) or database.'),
            'auth_type' => $schema->string()->enum(array_column(IntegrationAuthType::cases(), 'value'))->description('How the connection authenticates.')->required(),
            'auth_config' => $schema->object()->description('Credentials/endpoints for the auth_type (encrypted at rest). See tools_reference topic=integration.'),
            'description' => $schema->string()->description('What this connection is for.'),
            'default_headers' => $schema->object()->description('Headers sent on every request through this connection.'),
            'is_mcp' => $schema->boolean()->description('Legacy flag; prefer kind=mcp.'),
            'allow_insecure_tls' => $schema->boolean()->description('Skip TLS verification (use only for trusted internal hosts).'),
        ];
    }
}
