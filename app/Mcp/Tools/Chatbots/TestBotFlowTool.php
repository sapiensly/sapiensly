<?php

namespace App\Mcp\Tools\Chatbots;

use App\Models\User;
use App\Services\BotFlowExecutorService;
use App\Services\ConversationAttachmentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Test a chatbot's bot flow by stepping it with a message. Omit `state` to start a new test; pass back the returned `state` to continue the conversation.")]
class TestBotFlowTool extends ChatbotTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'chatbot_id' => ['required', 'string'],
            'message' => ['sometimes', 'string', 'max:4000'],
            'state' => ['sometimes', 'array'],
            // Simulated uploads to exercise file-input / file-routing nodes — only
            // type metadata is used, no bytes are stored.
            'attachments' => ['sometimes', 'array', 'max:10'],
            'attachments.*.original_name' => ['required', 'string'],
            'attachments.*.mime' => ['required', 'string'],
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

        $executor = app(BotFlowExecutorService::class);
        $state = $validated['state'] ?? $executor->initializeFlow($flow);

        $attachmentService = app(ConversationAttachmentService::class);
        $attachments = array_map(fn (array $a) => $attachmentService->descriptor(
            'test_'.uniqid(),
            $a['original_name'],
            $a['mime'],
            '',
            '',
            null,
        ), $validated['attachments'] ?? []);

        $action = $executor->processInput($flow, $state, (string) ($validated['message'] ?? ''), $attachments);

        return Response::json([
            'action_type' => $action->type->value,
            'data' => $action->data,
            'state' => $action->updatedState,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()->description('The chatbot to test.')->required(),
            'message' => $schema->string()->description('The user message to send into the flow (optional when sending attachments).'),
            'state' => $schema->object()->description('The flow state from a previous turn; omit to start fresh.'),
            'attachments' => $schema->array()
                ->items($schema->object([
                    'original_name' => $schema->string()->required(),
                    'mime' => $schema->string()->required(),
                ]))
                ->description("Simulated file uploads to test file-input / file-routing nodes, e.g. [{original_name:'receipt.pdf', mime:'application/pdf'}]. Only the type is used; no bytes are stored."),
        ];
    }
}
