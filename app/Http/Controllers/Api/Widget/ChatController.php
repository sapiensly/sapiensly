<?php

namespace App\Http\Controllers\Api\Widget;

use App\Enums\MessageRole;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchChannelMessageWorkflows;
use App\Models\Chatbot;
use App\Models\WidgetAttachment;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Models\WidgetSession;
use App\Services\Chatbots\ConversationEscalator;
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
    use Concerns\ResolvesVisitorConversation;

    public function __construct(
        private WidgetStreamService $streamService,
        private ConversationEscalator $escalator,
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

        $widgetConversation = $this->visitorConversation($request, $chatbot, $conversation);

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

        $widgetConversation = $this->visitorConversation($request, $chatbot, $conversation);

        if (! $widgetConversation) {
            return response()->json([
                'error' => 'Conversation not found',
                'message' => 'The specified conversation does not exist',
            ], 404);
        }

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:4000'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['string'],
        ]);

        $attachmentIds = $validated['attachment_ids'] ?? [];

        // A message must carry text, file(s), or both.
        if (trim((string) ($validated['content'] ?? '')) === '' && $attachmentIds === []) {
            return response()->json([
                'error' => 'Empty message',
                'message' => 'A message must include text or an attachment.',
            ], 422);
        }

        // Create the user message
        $message = WidgetMessage::create([
            'widget_conversation_id' => $widgetConversation->id,
            'role' => MessageRole::User,
            'content' => $validated['content'] ?? '',
        ]);

        // Link any pre-uploaded attachments to this message.
        if ($attachmentIds !== []) {
            WidgetAttachment::where('widget_conversation_id', $widgetConversation->id)
                ->whereIn('id', $attachmentIds)
                ->whereNull('widget_message_id')
                ->update(['widget_message_id' => $message->id]);
        }

        $widgetConversation->increment('message_count');

        // Update session activity
        $widgetConversation->session?->update(['last_activity_at' => now()]);

        // If the bot asked for an address so someone could follow up, this is
        // where the answer arrives. Keeping the promise depends on it landing on
        // the Contact rather than scrolling past in the transcript.
        $this->escalator->captureContactDetails($widgetConversation, (string) ($validated['content'] ?? ''));

        // Fire any channel.message_received workflows bound to this channel.
        DispatchChannelMessageWorkflows::dispatch(
            (string) $chatbot->channel_id,
            $chatbot->organization_id,
            $chatbot->user_id,
            [
                'channel' => [
                    'id' => $chatbot->channel_id,
                    'type' => 'widget',
                    'name' => $chatbot->name,
                ],
                'message' => [
                    'text' => $message->content ?? '',
                    'content_type' => 'text',
                ],
                'contact' => [
                    'id' => $widgetConversation->contact_id,
                ],
                'conversation_id' => $widgetConversation->id,
            ],
        );

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

        $widgetConversation = $this->visitorConversation($request, $chatbot, $conversation);

        if (! $widgetConversation) {
            return response()->json([
                'error' => 'Conversation not found',
                'message' => 'The specified conversation does not exist',
            ], 404);
        }

        // Getting this wrong made the guard below compare against turn 1 forever:
        // every turn after the first looked "already answered" and the widget
        // went silent from the second message on. The ordering that prevents it
        // now lives on the model.
        $lastMessage = $widgetConversation->lastUserMessage();

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

        // A person has this conversation — the bot does not get to talk over
        // them. Checked here rather than inside the stream so no SSE connection,
        // no provider call and no tokens are spent on a turn that must not
        // happen. The visitor's message is already stored; it is waiting for a
        // human, not lost.
        if (! $widgetConversation->botMayReply()) {
            return response()->json([
                'error' => 'Handled by a person',
                'message' => 'This conversation is being handled by a person',
            ], 409);
        }

        // The AI Bot runs on its Bot Flow roster.
        return $this->streamService->stream($chatbot, $widgetConversation);
    }
}
