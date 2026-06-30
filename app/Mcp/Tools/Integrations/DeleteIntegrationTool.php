<?php

namespace App\Mcp\Tools\Integrations;

use App\Mcp\Tools\SapiensTool;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\IntegrationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete an integration (connection). Tools that reference it will no longer authenticate — confirm with the user first.')]
class DeleteIntegrationTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'integration_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $integration = Integration::query()->forAccountContext($user)->find($validated['integration_id']);
        if ($integration === null) {
            return Response::error("No integration '{$validated['integration_id']}' is visible to you.");
        }

        if (! $user->can('delete', $integration)) {
            return Response::error('You do not have permission to delete this integration.');
        }

        app(IntegrationService::class)->delete($integration);

        return Response::json(['deleted' => true, 'integration_id' => $validated['integration_id']]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()->description('The id of the integration to delete.')->required(),
        ];
    }
}
