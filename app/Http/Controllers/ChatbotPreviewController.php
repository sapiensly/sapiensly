<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Models\Chatbot;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Models\WidgetSession;
use App\Services\WidgetStreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles preview chat functionality for chatbot testing.
 *
 * This controller allows authenticated users to test their chatbots
 * with real AI responses before deploying them.
 */
class ChatbotPreviewController extends Controller
{
    public function __construct(
        private WidgetStreamService $streamService
    ) {}

    /**
     * Get or create a preview session and conversation.
     *
     * POST /chatbots/{chatbot}/preview/init
     */
    public function init(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorize('view', $chatbot);

        // Get or create a preview session for this user
        $session = WidgetSession::firstOrCreate(
            [
                'chatbot_id' => $chatbot->id,
                'visitor_email' => 'preview-'.$request->user()->id.'@preview.local',
            ],
            [
                'session_token' => bin2hex(random_bytes(32)),
                'visitor_name' => 'Preview User',
                'visitor_metadata' => ['is_preview' => true, 'user_id' => $request->user()->id],
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'last_activity_at' => now(),
            ]
        );

        // Get existing conversation or create new one
        $conversation = WidgetConversation::where('widget_session_id', $session->id)
            ->where('is_resolved', false)
            ->latest()
            ->first();

        if (! $conversation) {
            $conversation = WidgetConversation::create([
                'chatbot_id' => $chatbot->id,
                'widget_session_id' => $session->id,
                'title' => 'Preview Conversation',
                'message_count' => 0,
                'is_resolved' => false,
                'is_abandoned' => false,
                'metadata' => ['is_preview' => true],
            ]);
        }

        // Load existing messages
        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (WidgetMessage $msg) => [
                'id' => $msg->id,
                'role' => $msg->role->value,
                'content' => $msg->content,
                'created_at' => $msg->created_at->toISOString(),
            ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $messages,
        ]);
    }

    /**
     * Send a message in preview mode.
     *
     * POST /chatbots/{chatbot}/preview/send
     */
    public function send(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorize('view', $chatbot);

        $validated = $request->validate([
            'conversation_id' => ['required', 'string'],
            'content' => ['required', 'string', 'max:4000'],
        ]);

        $conversation = WidgetConversation::where('chatbot_id', $chatbot->id)
            ->where('id', $validated['conversation_id'])
            ->first();

        if (! $conversation) {
            return response()->json([
                'error' => 'Conversation not found',
            ], 404);
        }

        // Verify the conversation belongs to this user's preview session
        $session = $conversation->session;
        if (! $session || ! str_starts_with($session->visitor_email ?? '', 'preview-'.$request->user()->id)) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 403);
        }

        // Create the user message
        $message = WidgetMessage::create([
            'widget_conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => $validated['content'],
        ]);

        $conversation->increment('message_count');
        $session->update(['last_activity_at' => now()]);

        return response()->json([
            'message_id' => $message->id,
            'role' => 'user',
            'content' => $message->content,
            'created_at' => $message->created_at->toISOString(),
        ], 201);
    }

    /**
     * Stream the AI response for a preview conversation.
     *
     * GET /chatbots/{chatbot}/preview/stream/{conversation}
     */
    public function stream(Request $request, Chatbot $chatbot, string $conversationId): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $chatbot);

        $conversation = WidgetConversation::where('chatbot_id', $chatbot->id)
            ->where('id', $conversationId)
            ->first();

        if (! $conversation) {
            return response()->json([
                'error' => 'Conversation not found',
            ], 404);
        }

        // Verify the conversation belongs to this user's preview session
        $session = $conversation->session;
        if (! $session || ! str_starts_with($session->visitor_email ?? '', 'preview-'.$request->user()->id)) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 403);
        }

        // Get the last user message (reorder to override relationship's default order)
        $lastMessage = $conversation->messages()
            ->where('role', MessageRole::User)
            ->reorder('created_at', 'desc')
            ->first();

        if (! $lastMessage) {
            return response()->json([
                'error' => 'No message to respond to',
            ], 400);
        }

        // Check if we already have a response for this message
        $existingResponse = $conversation->messages()
            ->where('role', MessageRole::Assistant)
            ->where('created_at', '>', $lastMessage->created_at)
            ->first();

        if ($existingResponse) {
            // Return the existing response as a stream (for retries/recovery)
            return $this->streamExistingResponse($existingResponse);
        }

        // Get the target (Agent or AgentTeam)
        $chatbot->load(['agent', 'agentTeam']);
        $target = $chatbot->agent ?? $chatbot->agentTeam;

        if (! $target) {
            return response()->json([
                'error' => 'No agent or team configured for this chatbot',
            ], 400);
        }

        // Stream the response
        return $this->streamService->stream($chatbot, $conversation, $target);
    }

    /**
     * Stream an existing response (for recovery when SSE fails).
     */
    private function streamExistingResponse(WidgetMessage $message): StreamedResponse
    {
        return response()->stream(function () use ($message) {
            // Send the existing content
            echo 'data: '.json_encode(['content' => $message->content])."\n\n";
            $this->flushOutput();

            // Send done events
            echo 'data: '.json_encode(['type' => 'done'])."\n\n";
            $this->flushOutput();

            echo "data: [DONE]\n\n";
            $this->flushOutput();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Flush output buffers safely.
     */
    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Clear the preview conversation and start fresh.
     *
     * POST /chatbots/{chatbot}/preview/clear
     */
    public function clear(Request $request, Chatbot $chatbot): JsonResponse
    {
        $this->authorize('view', $chatbot);

        $validated = $request->validate([
            'conversation_id' => ['required', 'string'],
        ]);

        $conversation = WidgetConversation::where('chatbot_id', $chatbot->id)
            ->where('id', $validated['conversation_id'])
            ->first();

        if ($conversation) {
            // Verify the conversation belongs to this user's preview session
            $session = $conversation->session;
            if ($session && str_starts_with($session->visitor_email ?? '', 'preview-'.$request->user()->id)) {
                // Delete all messages and mark as resolved
                $conversation->messages()->delete();
                $conversation->update([
                    'message_count' => 0,
                    'is_resolved' => true,
                ]);
            }
        }

        // Create a new conversation
        $session = WidgetSession::where('chatbot_id', $chatbot->id)
            ->where('visitor_email', 'preview-'.$request->user()->id.'@preview.local')
            ->first();

        if ($session) {
            $newConversation = WidgetConversation::create([
                'chatbot_id' => $chatbot->id,
                'widget_session_id' => $session->id,
                'title' => 'Preview Conversation',
                'message_count' => 0,
                'is_resolved' => false,
                'is_abandoned' => false,
                'metadata' => ['is_preview' => true],
            ]);

            return response()->json([
                'conversation_id' => $newConversation->id,
                'messages' => [],
            ]);
        }

        return response()->json([
            'error' => 'Session not found',
        ], 404);
    }
}
