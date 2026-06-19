<?php

namespace App\Mcp\Tools\Agents;

use App\Enums\MessageRole;
use App\Mcp\Tools\SapiensTool;
use App\Models\Agent;
use App\Models\Message;
use App\Models\User;
use App\Services\LLMService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Send a single message to a Sapiensly agent and get its reply (synchronous). Use list_agents to find an agent id.')]
class InvokeAgentTool extends SapiensTool
{
    protected const ABILITY = 'agents:invoke';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'string'],
            'message' => ['required', 'string', 'max:8000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $agent = Agent::query()->forAccountContext($user)->findOrFail($validated['agent_id']);
        } catch (ModelNotFoundException) {
            return Response::error("No agent '{$validated['agent_id']}' is visible to you.");
        }

        try {
            $reply = app(LLMService::class)
                ->setContext($user)
                ->chat($agent, [
                    new Message(['role' => MessageRole::User, 'content' => $validated['message']]),
                ]);
        } catch (\Throwable $e) {
            return Response::error('The agent could not respond: '.$e->getMessage());
        }

        return Response::json([
            'agent_id' => $agent->id,
            'reply' => $reply,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('The id of the agent to message.')->required(),
            'message' => $schema->string()->description('The user message to send to the agent.')->required(),
        ];
    }
}
