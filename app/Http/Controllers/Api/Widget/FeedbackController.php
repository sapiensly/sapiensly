<?php

namespace App\Http\Controllers\Api\Widget;

use App\Http\Controllers\Api\Widget\Concerns\ResolvesVisitorConversation;
use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles conversation feedback (ratings and comments).
 */
class FeedbackController extends Controller
{
    use ResolvesVisitorConversation;

    /**
     * Submit feedback for a conversation.
     *
     * POST /api/widget/v1/conversations/{conversation}/feedback
     */
    public function store(Request $request, string $conversation): JsonResponse
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
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'feedback' => ['nullable', 'string', 'max:1000'],
            'is_resolved' => ['nullable', 'boolean'],
        ]);

        $widgetConversation->update([
            'rating' => $validated['rating'],
            'feedback' => $validated['feedback'] ?? null,
            'is_resolved' => $validated['is_resolved'] ?? $widgetConversation->is_resolved,
        ]);

        return response()->json([
            'conversation_id' => $widgetConversation->id,
            'rating' => $widgetConversation->rating,
            'feedback' => $widgetConversation->feedback,
            'is_resolved' => $widgetConversation->is_resolved,
            'updated_at' => $widgetConversation->updated_at->toISOString(),
        ]);
    }
}
