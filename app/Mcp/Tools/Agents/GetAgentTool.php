<?php

namespace App\Mcp\Tools\Agents;

use App\Mcp\Tools\SapiensTool;
use App\Models\Agent;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get the full configuration of an agent: its model, system prompt, attached tools and knowledge bases.')]
class GetAgentTool extends SapiensTool
{
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

        return Response::json([
            'id' => $agent->id,
            'name' => $agent->name,
            'type' => $agent->type?->value,
            'status' => $agent->status?->value,
            'description' => $agent->description,
            'model' => $agent->model,
            'web_search' => $agent->web_search,
            'system_prompt' => $agent->prompt_template,
            'tools' => $agent->tools()->get()->map(fn (Tool $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type?->value,
                'effect' => $t->effect?->value,
            ])->values(),
            'knowledge_bases' => $agent->loadKnowledgeBases(['id', 'name'])
                ->map(fn ($kb) => ['id' => $kb->id, 'name' => $kb->name])
                ->values(),
        ]);
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
