<?php

namespace App\Mcp\Tools\Agents;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\AiProviderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List the chat model ids you can assign to an agent — the same catalog the web picker uses (models reachable via your own key or the platform-wide key). Use a returned value as the model for create_agent or update_agent.')]
class ListAgentModelsTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Response::json([
            'models' => app(AiProviderService::class)->getEnabledChatModels($user),
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
