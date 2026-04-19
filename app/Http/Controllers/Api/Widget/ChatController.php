<?php

namespace App\Http\Controllers\Api\Widget;

use App\Enums\MessageRole;
use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Models\WidgetSession;
use App\Services\WidgetStreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles chat functionality for the widget.
 *
 * This controller manages conversations and messages, including
 * SSE streaming for real-time AI responses.
 */
class ChatController extends Controller
{
    public function __construct(
        private WidgetStreamService $streamService
    ) {}

    /**
     * Create a new conversation.
     *
     * POST /api/widget/v1/conversations
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        $validated = $request->validate([
            'session_token' => ['required', 'string'],
            'initial_message' => ['nullable', 'string', 'max:4000'],
        ]);

        // Find the session
        $session = WidgetSession::where('chatbot_id', $chatbot->id)
            ->where('session_token', $validated['session_token'])
            ->first();

        if (! $session) {
            return response()->json([
                'error' => 'Session not found',
                'message' => 'Invalid session token',
            ], 404);
        }

        // Create the conversation — propagate channel_id + contact_id so the
        // shared abstraction stays consistent with the Chatbot's channel.
        $conversation = WidgetConversation::create([
            'chatbot_id' => $chatbot->id,
            'channel_id' => $chatbot->channel_id,
            'contact_id' => $session->contact_id,
            'widget_session_id' => $session->id,
            'title' => null,
            'message_count' => 0,
            'is_resolved' => false,
            'is_abandoned' => false,
        ]);

        // Update session activity
        $session->update(['last_activity_at' => now()]);

        $response = [
            'conversation_id' => $conversation->id,
            'created_at' => $conversation->created_at->toISOString(),
        ];

        // If initial message provided, add it
        if (! empty($validated['initial_message'])) {
            $message = WidgetMessage::create([
                'widget_conversation_id' => $conversation->id,
                'role' => MessageRole::User,
                'content' => $validated['initial_message'],
            ]);

            $conversation->increment('message_count');

            $response['initial_message'] = [
                'id' => $message->id,
                'role' => 'user',
                'content' => $message->content,
                'created_at' => $message->created_at->toISOString(),
            ];
        }

        return response()->json($response, 201);
    }

    /**
     * Get messages for a conversation.
     *
     * GET /api/widget/v1/conversations/{conversation}/messages
     */
    public function messages(Request $request, string $conversation): JsonResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        $widgetConversation = WidgetConversation::where('chatbot_id', $chatbot->id)
            ->where('id', $conversation)
            ->first();

        if (! $widgetConversation) {
            return response()->json([
                'error' => 'Conversation not found',
                'message' => 'The specified conversation does not exist',
            ], 404);
        }

        $messages = $widgetConversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (WidgetMessage $msg) => [
                'id' => $msg->id,
                'role' => $msg->role->value,
                'content' => $msg->content,
                'created_at' => $msg->created_at->toISOString(),
            ]);

        return response()->json([
            'conversation_id' => $widgetConversation->id,
            'messages' => $messages,
        ]);
    }

    /**
     * Send a message and get a streamed response.
     *
     * POST /api/widget/v1/conversations/{conversation}/messages
     *
     * This endpoint saves the user message and returns immediately.
     * Use the /stream endpoint to get the AI response.
     */
    public function sendMessage(Request $request, string $conversation): JsonResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        $widgetConversation = WidgetConversation::where('chatbot_id', $chatbot->id)
            ->where('id', $conversation)
            ->first();

        if (! $widgetConversation) {
            return response()->json([
                'error' => 'Conversation not found',
                'message' => 'The specified conversation does not exist',
            ], 404);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ]);

        // Create the user message
        $message = WidgetMessage::create([
            'widget_conversation_id' => $widgetConversation->id,
            'role' => MessageRole::User,
            'content' => $validated['content'],
        ]);

        $widgetConversation->increment('message_count');

        // Update session activity
        $widgetConversation->session?->update(['last_activity_at' => now()]);

        return response()->json([
            'message_id' => $message->id,
            'role' => 'user',
            'content' => $message->content,
            'created_at' => $message->created_at->toISOString(),
            'stream_url' => route('widget.conversations.stream', [
                'conversation' => $widgetConversation->id,
            ]),
        ], 201);
    }

    /**
     * Stream the AI response for a conversation.
     *
     * GET /api/widget/v1/conversations/{conversation}/stream
     *
     * Returns a Server-Sent Events stream with the AI response.
     */
    public function stream(Request $request, string $conversation): StreamedResponse|JsonResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        $widgetConversation = WidgetConversation::where('chatbot_id', $chatbot->id)
            ->where('id', $conversation)
            ->first();

        if (! $widgetConversation) {
            return response()->json([
                'error' => 'Conversation not found',
                'message' => 'The specified conversation does not exist',
            ], 404);
        }

        // Get the last user message
        $lastMessage = $widgetConversation->messages()
            ->where('role', MessageRole::User)
            ->latest()
            ->first();

        if (! $lastMessage) {
            return response()->json([
                'error' => 'No message to respond to',
                'message' => 'Send a message first',
            ], 400);
        }

        // Check if we already have a response for this message
        $hasResponse = $widgetConversation->messages()
            ->where('role', MessageRole::Assistant)
            ->where('created_at', '>', $lastMessage->created_at)
            ->exists();

        if ($hasResponse) {
            return response()->json([
                'error' => 'Already responded',
                'message' => 'This message has already been answered',
            ], 400);
        }

        // Get the target (Agent or AgentTeam)
        $chatbot->load(['agent', 'agentTeam']);
        $target = $chatbot->agent ?? $chatbot->agentTeam;

        if (! $target) {
            return response()->json([
                'error' => 'No target configured',
                'message' => 'This chatbot has no agent or team configured',
            ], 500);
        }

        // Stream the response
        return $this->streamService->stream($chatbot, $widgetConversation, $target);
    }
}
