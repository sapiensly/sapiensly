<?php

namespace App\Mcp\Tools\Chatbots;

use App\Models\User;
use App\Rules\ValidBotFlowDefinition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Replace a chatbot's bot flow definition (the node/edge graph). The definition is validated; read_bot_flow / scaffold_bot_flow first to get a valid shape.")]
class UpdateBotFlowTool extends ChatbotTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'chatbot_id' => ['required', 'string'],
            'definition' => ['required', 'array', new ValidBotFlowDefinition],
            'name' => ['sometimes', 'string', 'max:255'],
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

        $changes = ['definition' => $validated['definition']];
        if (isset($validated['name'])) {
            $changes['name'] = $validated['name'];
        }

        $flow->update($changes);

        return Response::json(['updated' => true, 'flow_id' => $flow->id, 'version' => $flow->version]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()->description('The chatbot whose flow to update.')->required(),
            'definition' => $schema->object()->description('The full flow definition: { nodes: [...], edges: [...] }.')->required(),
            'name' => $schema->string()->description('Optional new name for the flow.'),
        ];
    }
}
