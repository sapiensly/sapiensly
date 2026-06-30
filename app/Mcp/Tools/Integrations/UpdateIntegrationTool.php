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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update an integration (connection). Only the fields you pass change (partial update). Empty/omitted auth_config secret fields keep their stored value, so re-send a secret only when changing it.')]
class UpdateIntegrationTool extends SapiensTool
{
    use PresentsIntegration;

    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $integrationId = $request->validate(['integration_id' => ['required', 'string']])['integration_id'];

        try {
            $integration = Integration::query()->forAccountContext($user)->findOrFail($integrationId);
        } catch (ModelNotFoundException) {
            return Response::error("No integration '{$integrationId}' is visible to you.");
        }

        if (! $user->can('update', $integration)) {
            return Response::error('You do not have permission to update this integration.');
        }

        $data = $request->all();
        unset($data['integration_id']);

        $kind = $data['kind'] ?? $integration->kind?->value;
        $validator = ValidatorFactory::make($data, IntegrationRules::update($kind));
        $validator->after(function (Validator $v) use ($data) {
            IntegrationRules::validateOAuth2OnUpdate($v, (array) ($data['auth_config'] ?? []));
        });
        $validated = $validator->validate();

        $integration = app(IntegrationService::class)->update($integration, $validated);

        return Response::json($this->integrationPayload($integration));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()->description('The id of the integration to update.')->required(),
            'name' => $schema->string()->description('New name.'),
            'base_url' => $schema->string()->description('New base URL / DSN.'),
            'auth_type' => $schema->string()->enum(array_column(IntegrationAuthType::cases(), 'value'))->description('New auth type.'),
            'auth_config' => $schema->object()->description('Auth credentials/endpoints to merge (empty secret fields keep their stored value).'),
            'description' => $schema->string()->description('New description.'),
            'default_headers' => $schema->object()->description('Replacement default headers.'),
            'status' => $schema->string()->enum(['active', 'inactive'])->description('active or inactive.'),
            'allow_insecure_tls' => $schema->boolean()->description('Skip TLS verification.'),
        ];
    }
}
