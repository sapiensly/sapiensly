<?php

namespace App\Mcp\Tools\Chatbots;

use App\Models\User;
use App\Services\BotFlows\BotFlowScaffolder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Generate a bot flow definition (nodes + edges) from a plain-language description, using the account\'s agents. Returns the definition WITHOUT saving — review it, then persist with update_bot_flow.')]
class ScaffoldBotFlowTool extends ChatbotTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'chatbot_id' => ['required', 'string'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $this->resolveChatbot($validated['chatbot_id'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No chatbot '{$validated['chatbot_id']}' is visible to you.");
        }

        $definition = app(BotFlowScaffolder::class)->scaffold(
            $validated['description'],
            $this->availableAgents($user),
            $user,
        );

        return Response::json(['definition' => $definition]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()->description('The chatbot to scaffold a flow for.')->required(),
            'description' => $schema->string()->description('Plain-language description of the desired conversation flow.')->required(),
        ];
    }
}
