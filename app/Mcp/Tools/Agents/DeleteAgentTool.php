<?php

namespace App\Mcp\Tools\Agents;

use App\Mcp\Tools\SapiensTool;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Permanently delete an agent. This cannot be undone — confirm with the user first.')]
class DeleteAgentTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $agent = Agent::query()->forAccountContext($user)->find($validated['agent_id']);
        if ($agent === null) {
            return Response::error("No agent '{$validated['agent_id']}' is visible to you.");
        }

        if (! $user->can('delete', $agent)) {
            return Response::error('You do not have permission to delete this agent.');
        }

        $agent->delete();

        return Response::json(['deleted' => true, 'agent_id' => $validated['agent_id']]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('The id of the agent to delete.')->required(),
        ];
    }
}
