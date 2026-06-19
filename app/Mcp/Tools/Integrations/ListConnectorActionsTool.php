<?php

namespace App\Mcp\Tools\Integrations;

use App\Mcp\Tools\SapiensTool;
use App\Models\Tool;
use App\Models\User;
use App\Services\Connectors\ConnectorActionResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the typed connector actions (inputs/outputs/effect) available from your integrations, optionally filtered to one integration. Use these when wiring connector.call workflow steps.')]
class ListConnectorActionsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'integration_id' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $query = Tool::query()->forAccountContext($user)->whereNotNull('config->integration_id')->orderBy('name');
        if (! empty($validated['integration_id'])) {
            $query->where('config->integration_id', $validated['integration_id']);
        }

        $resolver = app(ConnectorActionResolver::class);

        $actions = $query->get()->map(fn (Tool $tool) => $resolver->resolve($tool)->jsonSerialize())->values();

        return Response::json(['actions' => $actions]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()->description('Optional integration id to limit the actions to.'),
        ];
    }
}
