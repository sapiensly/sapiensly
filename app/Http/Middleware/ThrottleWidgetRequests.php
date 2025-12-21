<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting middleware for Widget API requests.
 *
 * Applies different rate limits based on the endpoint:
 * - Config: 60 requests per minute (initial widget load)
 * - Session: 30 requests per minute
 * - Chat messages: 20 requests per minute
 * - Stream: 10 requests per minute (long-running)
 */
class ThrottleWidgetRequests
{
    public function __construct(
        private RateLimiter $limiter
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->resolveRequestKey($request);
        $maxAttempts = $this->getMaxAttempts($request);
        $decaySeconds = 60; // 1 minute window

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);

            return response()->json([
                'error' => 'Too many requests',
                'message' => 'Rate limit exceeded. Please try again later.',
                'retry_after' => $retryAfter,
            ], 429, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        $this->limiter->hit($key, $decaySeconds);

        $response = $next($request);

        // Add rate limit headers to response
        if ($response instanceof Response) {
            $response->headers->set('X-RateLimit-Limit', $maxAttempts);
            $response->headers->set('X-RateLimit-Remaining', max(0, $maxAttempts - $this->limiter->attempts($key)));
        }

        return $response;
    }

    /**
     * Generate a unique key for rate limiting.
     *
     * Uses a combination of IP address and session token (if available)
     * to identify unique visitors.
     */
    private function resolveRequestKey(Request $request): string
    {
        $chatbot = $request->attributes->get('chatbot');
        $chatbotId = $chatbot?->id ?? 'unknown';

        // Try to get session token from request
        $sessionToken = $request->input('session_token')
            ?? $request->header('X-Session-Token')
            ?? '';

        // Combine IP, chatbot ID, and session token
        $identifier = $request->ip().'|'.$chatbotId.'|'.$sessionToken;

        // Include the route name for endpoint-specific limits
        $routeName = $request->route()?->getName() ?? 'widget';

        return 'widget_throttle:'.$routeName.':'.sha1($identifier);
    }

    /**
     * Get the maximum number of attempts based on the endpoint.
     */
    private function getMaxAttempts(Request $request): int
    {
        $routeName = $request->route()?->getName() ?? '';

        return match (true) {
            str_contains($routeName, 'config') => 60,
            str_contains($routeName, 'sessions') => 30,
            str_contains($routeName, 'stream') => 10,
            str_contains($routeName, 'send') => 20,
            str_contains($routeName, 'feedback') => 30,
            default => 30,
        };
    }
}
