<?php

namespace App\Http\Controllers\Api\Widget;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles error reporting from the widget.
 *
 * This controller receives error reports from the JavaScript widget
 * and logs them for monitoring and debugging.
 */
class ErrorController extends Controller
{
    /**
     * Store an error report.
     *
     * POST /api/widget/v1/errors
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chatbot_id' => ['nullable', 'string'],
            'session_id' => ['nullable', 'string'],
            'error' => ['required', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:5000'],
            'context' => ['nullable', 'array'],
        ]);

        // Log the error for monitoring
        Log::channel('widget')->warning('Widget error reported', [
            'chatbot_id' => $validated['chatbot_id'] ?? null,
            'session_id' => $validated['session_id'] ?? null,
            'error' => $validated['error'],
            'stack' => $validated['stack'] ?? null,
            'context' => $validated['context'] ?? [],
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['status' => 'received']);
    }
}
