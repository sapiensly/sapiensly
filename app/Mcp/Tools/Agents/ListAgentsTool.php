<?php

namespace App\Mcp\Tools\Agents;

use App\Mcp\Tools\SapiensTool;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the Sapiensly agents you can use, with their id, name, type and status.')]
class ListAgentsTool extends SapiensTool
{
    protected const ABILITY = 'agents:invoke';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $agents = Agent::query()->forAccountContext($user)->get();

        return Response::json([
            'agents' => $agents->map(fn (Agent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type?->value,
                'status' => $a->status?->value,
                'description' => $a->description,
                'model' => $a->model,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
