<?php

namespace App\Http\Controllers\Api\Widget;

use App\Http\Controllers\Controller;
use App\Models\ChatbotApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles public configuration endpoint for the widget.
 *
 * This endpoint is used when the widget first loads to fetch
 * the chatbot configuration (appearance, behavior settings).
 */
class ConfigController extends Controller
{
    /**
     * Get chatbot configuration by API token.
     *
     * GET /api/widget/v1/config/{token}
     */
    public function show(Request $request, string $token): JsonResponse
    {
        // Find the token
        $apiToken = ChatbotApiToken::where('token', $token)->first();

        if (! $apiToken) {
            return response()->json([
                'error' => 'Invalid token',
                'message' => 'The provided token is not valid',
            ], 404);
        }

        if ($apiToken->isExpired()) {
            return response()->json([
                'error' => 'Expired token',
                'message' => 'The provided token has expired',
            ], 401);
        }

        $chatbot = $apiToken->chatbot;

        if (! $chatbot) {
            return response()->json([
                'error' => 'Chatbot not found',
                'message' => 'The chatbot associated with this token no longer exists',
            ], 404);
        }

        if (! $chatbot->isActive()) {
            return response()->json([
                'error' => 'Chatbot inactive',
                'message' => 'This chatbot is not currently active',
            ], 403);
        }

        // Set chatbot on request for origin validation
        $request->attributes->set('chatbot', $chatbot);

        // Update last used
        $apiToken->touchLastUsed();

        return response()->json([
            'chatbot_id' => $chatbot->id,
            'name' => $chatbot->name,
            'config' => [
                'appearance' => $chatbot->getAppearanceConfig(),
                'behavior' => $chatbot->getBehaviorConfig(),
            ],
        ]);
    }
}
