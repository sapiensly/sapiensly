<?php

namespace App\Ai\Tools\Builder;

use App\Models\Tool as ToolModel;
use App\Models\User;
use App\Services\Connectors\ConnectorActionResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Lists the typed connector actions bound to an integration — the capability
 * contracts a connector.call composes against: id, typed inputs, outputs,
 * read/write effect, blast radius and whether the action is gated. Each action
 * maps to one configured Tool.
 */
class ListConnectorActionsTool implements Tool
{
    public function __construct(
        private ConnectorActionResolver $resolver,
        private User $user,
    ) {}

    public function name(): string
    {
        return 'list_connector_actions';
    }

    public function description(): string
    {
        return 'List the typed connector actions available on a configured integration. Each action is a capability contract (id, inputs, outputs, effect read/write, blast_radius, safe, typed). Call this before composing a connector.call so you reference real action ids and typed inputs. Returns {integration_id, actions: [...]}.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('The integration to list actions for (from list_available_integrations).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $integrationId = trim((string) ($request->all()['integration_id'] ?? ''));

        if ($integrationId === '') {
            return json_encode(['ok' => false, 'error' => 'integration_id is required.'], JSON_THROW_ON_ERROR);
        }

        $actions = ToolModel::query()
            ->forAccountContext($this->user)
            ->where('config->integration_id', $integrationId)
            ->orderBy('name')
            ->get()
            ->map(fn (ToolModel $tool): array => $this->resolver->resolve($tool)->jsonSerialize())
            ->all();

        return json_encode([
            'integration_id' => $integrationId,
            'actions' => $actions,
        ], JSON_THROW_ON_ERROR);
    }
}
