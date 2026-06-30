<?php

namespace App\Mcp\Tools\Integrations;

use App\Mcp\Tools\Integrations\Concerns\PresentsIntegration;
use App\Mcp\Tools\SapiensTool;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get the full configuration of an integration (connection): its kind (http/mcp/database), base URL, auth type, environments and request count. Credentials in auth_config are masked.')]
class GetIntegrationTool extends SapiensTool
{
    use PresentsIntegration;

    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'integration_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $integration = Integration::query()->forAccountContext($user)->findOrFail($validated['integration_id']);
        } catch (ModelNotFoundException) {
            return Response::error("No integration '{$validated['integration_id']}' is visible to you.");
        }

        if (! $user->can('view', $integration)) {
            return Response::error('You do not have permission to view this integration.');
        }

        return Response::json($this->integrationPayload($integration));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()->description('The id of the integration to inspect.')->required(),
        ];
    }
}
