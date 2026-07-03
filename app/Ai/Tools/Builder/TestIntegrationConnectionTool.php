<?php

namespace App\Ai\Tools\Builder;

use App\Models\Integration;
use App\Models\User;
use App\Services\Builder\Integrations\IntegrationAuthoring;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Builder power #1. Fire one real request against a draft integration to verify it
 * works before relying on it — the behavioral verification gate. Tenant-scoped:
 * only the user's own integrations are reachable.
 */
class TestIntegrationConnectionTool implements Tool
{
    public function __construct(
        private IntegrationAuthoring $authoring,
        private User $user,
    ) {}

    public function name(): string
    {
        return 'test_connection';
    }

    public function description(): string
    {
        return 'Verify a connection by making one real request through it. Provide integration_id and an optional lightweight test_path (e.g. "/me" or "/crm/v3/objects/contacts?limit=1"). Returns {ok, status, sample}. Call this after the user has authorized the integration; do not report a connection as working until this passes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()->description('The id returned by create_integration.')->required(),
            'test_path' => $schema->string()->description('Optional path appended to the base URL for the test request.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $id = (string) ($args['integration_id'] ?? '');

        $integration = Integration::query()
            ->forAccountContext($this->user)
            ->find($id);

        if (! $integration instanceof Integration) {
            return json_encode(['ok' => false, 'error' => 'Integration not found for this tenant.'], JSON_THROW_ON_ERROR);
        }

        return json_encode(
            $this->authoring->test($integration, $args['test_path'] ?? null, $this->user),
            JSON_THROW_ON_ERROR
        );
    }
}
