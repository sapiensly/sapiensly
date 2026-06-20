<?php

namespace App\Mcp\Tools\Chatbots;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Permanently delete a chatbot (and its bot flow). This cannot be undone — confirm with the user first.')]
class DeleteChatbotTool extends ChatbotTool
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

        if (! $user->can('delete', $chatbot)) {
            return Response::error('You do not have permission to delete this chatbot.');
        }

        $chatbot->delete();

        return Response::json(['deleted' => true, 'chatbot_id' => $validated['chatbot_id']]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()->description('The id of the chatbot to delete.')->required(),
        ];
    }
}
