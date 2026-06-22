<?php

namespace App\Mcp\Tools\Chats;

use App\Mcp\Tools\SapiensTool;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Throwable;

#[Description('Continue an existing chat: post a user message and get the assistant reply synchronously, as the chat\'s configured model/agent (its tools + knowledge apply). The turn is persisted to the conversation like any other. Find the chat_id with list_chats or search_chat_messages.')]
class ContinueChatTool extends SapiensTool
{
    protected const ABILITY = 'agents:invoke';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'chat_id' => ['required', 'string'],
            'message' => ['required', 'string', 'max:8000'],
            'web_search' => ['nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $chat = Chat::query()->forAccountContext($user)->findOrFail($validated['chat_id']);
        } catch (ModelNotFoundException) {
            return Response::error("No chat '{$validated['chat_id']}' is visible to you.");
        }

        $content = trim($validated['message']);
        if ($content === '') {
            return Response::error('The message is empty.');
        }

        ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'user',
            'content' => $content,
            'model' => $chat->model,
            'status' => 'complete',
        ]);

        $placeholder = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => null,
            'model' => $chat->model,
            'agent_id' => $chat->agent_id,
            'status' => 'pending',
        ]);

        try {
            // Run the turn inline (no queue) so MCP gets the reply back. Uses the
            // chat's own model/agent/tools; broadcasts are harmless no-ops here.
            app(ChatAiService::class)->streamMessage(
                $placeholder,
                $content,
                null,
                (bool) ($validated['web_search'] ?? false),
                $chat->tool_ids ?? [],
            );
        } catch (Throwable $e) {
            return Response::error('The chat turn failed: '.$e->getMessage());
        }

        $placeholder->refresh();

        if ($placeholder->status === 'error') {
            return Response::error('The chat turn failed: '.($placeholder->error ?? 'unknown error'));
        }

        return Response::json([
            'chat_id' => $chat->id,
            'message_id' => $placeholder->id,
            'reply' => $placeholder->content,
            'status' => $placeholder->status,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chat_id' => $schema->string()->description('The id of the chat to continue.')->required(),
            'message' => $schema->string()->description('The user message to send into the chat.')->required(),
            'web_search' => $schema->boolean()->description('Enable web search for this turn (default false; only applies on providers that support it).'),
        ];
    }
}
