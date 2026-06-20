<?php

namespace App\Mcp\Tools\Chatbots;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Read a single chatbot's full configuration: status, channel, widget config (appearance/behavior/advanced), allowed origins, whether it has a bot flow, and the agent roster the flow hands off to.")]
class GetChatbotTool extends ChatbotTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'chatbot_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $chatbot = $this->resolveChatbot($validated['chatbot_id'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No chatbot '{$validated['chatbot_id']}' is visible to you.");
        }

        $chatbot->loadMissing('channel', 'botFlow');
        $flow = $chatbot->botFlow;

        return Response::json([
            'id' => $chatbot->id,
            'name' => $chatbot->name,
            'description' => $chatbot->description,
            'status' => $chatbot->status?->value,
            'channel' => $chatbot->channel === null ? null : [
                'id' => $chatbot->channel->id,
                'name' => $chatbot->channel->name,
                'type' => $chatbot->channel->channel_type?->value,
                'status' => $chatbot->channel->status?->value,
            ],
            'allowed_origins' => $chatbot->allowed_origins ?? [],
            'config' => $chatbot->config ?? [],
            'has_flow' => $flow !== null,
            'flow' => $flow === null ? null : [
                'id' => $flow->id,
                'status' => $flow->status?->value,
                'version' => $flow->version,
            ],
            // The agents the flow hands off to, keyed by role — the same roster
            // the orchestrator resolves agent_handoff target_agent against.
            'roster' => $flow === null ? [] : collect($flow->roster())
                ->map(fn (?Agent $agent) => $agent === null ? null : [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                ])
                ->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()->description('The chatbot to read.')->required(),
        ];
    }
}
