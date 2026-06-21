<?php

namespace App\Mcp\Tools\Agents;

use App\Mcp\Tools\Agents\Concerns\PresentsAgent;
use App\Mcp\Tools\SapiensTool;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get the full configuration of an agent: its model, system prompt, attached tools and knowledge bases.')]
class GetAgentTool extends SapiensTool
{
    use PresentsAgent;

    protected const ABILITY = 'agents:invoke';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $agent = Agent::query()->forAccountContext($user)->findOrFail($validated['agent_id']);
        } catch (ModelNotFoundException) {
            return Response::error("No agent '{$validated['agent_id']}' is visible to you.");
        }

        return Response::json($this->agentPayload($agent));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('The id of the agent to inspect.')->required(),
        ];
    }
}
