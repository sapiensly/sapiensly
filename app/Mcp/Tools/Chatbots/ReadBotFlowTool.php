<?php

namespace App\Mcp\Tools\Chatbots;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Read a chatbot's bot flow: the node/edge graph (start, agent, menu, condition, connector, end) that drives the conversation.")]
class ReadBotFlowTool extends ChatbotTool
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

        $flow = $chatbot->botFlow;
        if ($flow === null) {
            return Response::error('This chatbot has no bot flow yet.');
        }

        return Response::json([
            'flow_id' => $flow->id,
            'status' => $flow->status?->value,
            'version' => $flow->version,
            'definition' => $flow->definition,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()->description('The chatbot whose flow to read.')->required(),
        ];
    }
}
