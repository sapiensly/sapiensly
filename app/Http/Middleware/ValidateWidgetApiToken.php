<?php

namespace App\Http\Middleware;

use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the Bearer token for Widget API requests.
 *
 * The token is sent in the Authorization header as: Bearer <token>
 * This middleware validates the token and attaches the chatbot to the request.
 */
class ValidateWidgetApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json([
                'error' => 'Missing API token',
                'message' => 'Authorization header with Bearer token is required',
            ], 401);
        }

        // Find the token
        $apiToken = ChatbotApiToken::where('token', $bearerToken)->first();

        if (! $apiToken) {
            return response()->json([
                'error' => 'Invalid API token',
                'message' => 'The provided token is not valid',
            ], 401);
        }

        // Check if token is expired
        if ($apiToken->isExpired()) {
            return response()->json([
                'error' => 'Expired API token',
                'message' => 'The provided token has expired',
            ], 401);
        }

        // Get the chatbot
        $chatbot = $apiToken->chatbot;

        if (! $chatbot) {
            return response()->json([
                'error' => 'Chatbot not found',
                'message' => 'The chatbot associated with this token no longer exists',
            ], 404);
        }

        // Check if chatbot is active
        if (! $chatbot->isActive()) {
            return response()->json([
                'error' => 'Chatbot inactive',
                'message' => 'This chatbot is not currently active',
            ], 403);
        }

        // Update last used timestamp
        $apiToken->touchLastUsed();

        // Attach chatbot and token to request for use in controllers
        $request->attributes->set('chatbot', $chatbot);
        $request->attributes->set('api_token', $apiToken);

        return $next($request);
    }
}
