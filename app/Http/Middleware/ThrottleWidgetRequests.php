<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting for the Widget API, in TWO buckets, because one bucket cannot do
 * both jobs at once.
 *
 * The per-visitor bucket is keyed on ip + chatbot + session token and exists for
 * FAIRNESS: it keeps one tab from monopolising the bot while an office full of
 * people behind a single NAT address each get their own allowance.
 *
 * That key is useless for ABUSE, though, because the session token is chosen by
 * whoever is calling: mint a fresh session (cheap) and you mint a fresh bucket
 * with it. Keyed that way alone, one address could open ~30 sessions a minute
 * and drive ~300 streams through them — each one a model call billed to the
 * tenant, with only the organization's spend budget underneath, and a budget
 * caps the total, not the rate.
 *
 * So the second bucket is keyed on ip + chatbot ONLY — nothing the caller can
 * rotate — and bounds what a single address can cost per minute no matter how
 * many identities it invents. A request must pass both.
 */
class ThrottleWidgetRequests
{
    private const WINDOW_SECONDS = 60;

    /** Per-visitor allowance: generous, since it only has to stop one tab running away. */
    private const PER_VISITOR = [
        'config' => 60,
        'sessions' => 30,
        'stream' => 10,
        'send' => 20,
        'feedback' => 30,
        'default' => 30,
    ];

    /**
     * Per-address ceiling, ignoring session identity. Higher than the per-visitor
     * figure so a shared office address is not punished for being shared, but
     * finite so a single address cannot spin the meter indefinitely.
     */
    private const PER_ADDRESS = [
        'config' => 120,
        'sessions' => 40,
        'stream' => 40,
        'send' => 60,
        'feedback' => 60,
        'default' => 60,
    ];

    public function __construct(
        private RateLimiter $limiter
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $this->routeBucket($request);

        $visitorKey = $this->key($request, $route, withSession: true);
        $addressKey = $this->key($request, $route, withSession: false);

        $visitorMax = self::PER_VISITOR[$route];
        $addressMax = self::PER_ADDRESS[$route];

        foreach ([[$visitorKey, $visitorMax], [$addressKey, $addressMax]] as [$key, $max]) {
            if ($this->limiter->tooManyAttempts($key, $max)) {
                $retryAfter = $this->limiter->availableIn($key);

                return response()->json([
                    'error' => 'Too many requests',
                    'message' => 'Rate limit exceeded. Please try again later.',
                    'retry_after' => $retryAfter,
                ], 429, [
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => $max,
                    'X-RateLimit-Remaining' => 0,
                ]);
            }
        }

        $this->limiter->hit($visitorKey, self::WINDOW_SECONDS);
        $this->limiter->hit($addressKey, self::WINDOW_SECONDS);

        $response = $next($request);

        // Report the allowance the caller is closest to spending.
        if ($response instanceof Response) {
            $visitorLeft = max(0, $visitorMax - $this->limiter->attempts($visitorKey));
            $addressLeft = max(0, $addressMax - $this->limiter->attempts($addressKey));

            $response->headers->set('X-RateLimit-Limit', $visitorLeft <= $addressLeft ? $visitorMax : $addressMax);
            $response->headers->set('X-RateLimit-Remaining', min($visitorLeft, $addressLeft));
        }

        return $response;
    }

    /**
     * The bucket key. `withSession: false` deliberately drops the one component
     * the caller controls, so the resulting ceiling cannot be multiplied by
     * inventing identities.
     */
    private function key(Request $request, string $route, bool $withSession): string
    {
        $chatbot = $request->attributes->get('chatbot');
        $identifier = $request->ip().'|'.($chatbot?->id ?? 'unknown');

        if ($withSession) {
            $identifier .= '|'.($request->input('session_token')
                ?? $request->header('X-Session-Token')
                ?? '');
        }

        return 'widget_throttle:'.($withSession ? 'visitor' : 'addr').':'.$route.':'.sha1($identifier);
    }

    /** Which quota family this route falls under. */
    private function routeBucket(Request $request): string
    {
        $routeName = $request->route()?->getName() ?? '';

        return match (true) {
            str_contains($routeName, 'config') => 'config',
            str_contains($routeName, 'sessions') => 'sessions',
            str_contains($routeName, 'stream') => 'stream',
            str_contains($routeName, 'send') => 'send',
            str_contains($routeName, 'feedback') => 'feedback',
            default => 'default',
        };
    }
}
