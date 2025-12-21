<?php

namespace App\Http\Controllers\Api\Widget;

use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use App\Models\WidgetSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Handles widget session management.
 *
 * Sessions track unique visitors across conversations.
 * A session persists in the browser's localStorage and can
 * be updated with visitor identification (email, name).
 */
class SessionController extends Controller
{
    /**
     * Create a new widget session.
     *
     * POST /api/widget/v1/sessions
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        $validated = $request->validate([
            'visitor_email' => ['nullable', 'email', 'max:255'],
            'visitor_name' => ['nullable', 'string', 'max:255'],
            'visitor_metadata' => ['nullable', 'array'],
        ]);

        // Generate a unique session token
        $sessionToken = Str::random(64);

        $session = WidgetSession::create([
            'chatbot_id' => $chatbot->id,
            'session_token' => $sessionToken,
            'visitor_email' => $validated['visitor_email'] ?? null,
            'visitor_name' => $validated['visitor_name'] ?? null,
            'visitor_metadata' => $validated['visitor_metadata'] ?? null,
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'referrer_url' => $request->header('Referer'),
            'page_url' => $request->input('page_url'),
            'last_activity_at' => now(),
        ]);

        return response()->json([
            'session_id' => $session->id,
            'session_token' => $sessionToken,
            'created_at' => $session->created_at->toISOString(),
        ], 201);
    }

    /**
     * Update an existing session with visitor information.
     *
     * PATCH /api/widget/v1/sessions/{session}
     */
    public function update(Request $request, string $session): JsonResponse
    {
        /** @var Chatbot $chatbot */
        $chatbot = $request->attributes->get('chatbot');

        // Find session by ID or session_token
        $widgetSession = WidgetSession::where('chatbot_id', $chatbot->id)
            ->where(function ($query) use ($session) {
                $query->where('id', $session)
                    ->orWhere('session_token', $session);
            })
            ->first();

        if (! $widgetSession) {
            return response()->json([
                'error' => 'Session not found',
                'message' => 'The specified session does not exist',
            ], 404);
        }

        $validated = $request->validate([
            'visitor_email' => ['nullable', 'email', 'max:255'],
            'visitor_name' => ['nullable', 'string', 'max:255'],
            'visitor_metadata' => ['nullable', 'array'],
            'page_url' => ['nullable', 'string', 'max:2048'],
        ]);

        // Merge metadata if provided
        $metadata = $widgetSession->visitor_metadata ?? [];
        if (isset($validated['visitor_metadata'])) {
            $metadata = array_merge($metadata, $validated['visitor_metadata']);
        }

        $widgetSession->update([
            'visitor_email' => $validated['visitor_email'] ?? $widgetSession->visitor_email,
            'visitor_name' => $validated['visitor_name'] ?? $widgetSession->visitor_name,
            'visitor_metadata' => $metadata,
            'page_url' => $validated['page_url'] ?? $widgetSession->page_url,
            'last_activity_at' => now(),
        ]);

        return response()->json([
            'session_id' => $widgetSession->id,
            'visitor_email' => $widgetSession->visitor_email,
            'visitor_name' => $widgetSession->visitor_name,
            'updated_at' => $widgetSession->updated_at->toISOString(),
        ]);
    }
}
