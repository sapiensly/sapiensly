<?php

namespace App\Http\Middleware;

use App\Services\Landing\ChatbotLandingOrigins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the Origin header for Widget API requests.
 *
 * This middleware checks that requests are coming from allowed origins
 * as configured in the chatbot settings. If no origins are configured,
 * all origins are allowed.
 */
class ValidateWidgetOrigin
{
    public function __construct(
        private readonly ChatbotLandingOrigins $landingOrigins,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Get the chatbot from the request (set by ValidateWidgetApiToken)
        // or from the config endpoint token lookup
        $chatbot = $request->attributes->get('chatbot');

        // For config endpoint, we validate after fetching the chatbot
        if (! $chatbot) {
            return $next($request);
        }

        $origin = $request->header('Origin');

        $configured = $chatbot->allowed_origins ?? [];

        // An empty list still means "allow every origin" — and it must keep
        // meaning that. Deriving landing origins into an empty list would flip
        // the bot to allow-only-those and silently lock out every site already
        // embedding it, which is the same trap as writing them into the column,
        // just computed at runtime. So the derived origins only ever WIDEN a
        // list the tenant already chose to restrict.
        if (empty($configured)) {
            return $next($request);
        }

        $allowedOrigins = array_merge($configured, $this->landingOrigins->for($chatbot->id));

        // Check if origin matches any allowed origin
        if ($origin && $this->isOriginAllowed($origin, $allowedOrigins)) {
            return $next($request);
        }

        // Origin header might not be present in some cases (e.g., same-origin)
        // We'll allow requests without Origin header if Referer matches
        $referer = $request->header('Referer');
        if ($referer) {
            $refererOrigin = $this->extractOrigin($referer);
            if ($refererOrigin && $this->isOriginAllowed($refererOrigin, $allowedOrigins)) {
                return $next($request);
            }
        }

        // If we have allowed origins configured but no valid origin found, reject
        if ($origin || $referer) {
            return response()->json([
                'error' => 'Origin not allowed',
                'message' => 'Requests from this origin are not permitted',
            ], 403);
        }

        // No Origin or Referer - this could be a server-to-server call or testing
        // Allow it but log a warning in production
        return $next($request);
    }

    /**
     * Check if the origin matches any allowed origin.
     *
     * Supports wildcard matching for subdomains: *.example.com
     */
    private function isOriginAllowed(string $origin, array $allowedOrigins): bool
    {
        $normalizedOrigin = $this->normalizeOrigin($origin);

        foreach ($allowedOrigins as $allowed) {
            $normalizedAllowed = $this->normalizeOrigin($allowed);

            // Exact match
            if ($normalizedOrigin === $normalizedAllowed) {
                return true;
            }

            // Wildcard subdomain match (e.g., *.example.com)
            if (str_starts_with($normalizedAllowed, '*.')) {
                $baseDomain = substr($normalizedAllowed, 2);
                $originHost = parse_url($normalizedOrigin, PHP_URL_HOST);

                if ($originHost === $baseDomain || str_ends_with($originHost, '.'.$baseDomain)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Normalize an origin URL.
     */
    private function normalizeOrigin(string $origin): string
    {
        $origin = strtolower(trim($origin));

        // Remove trailing slash
        return rtrim($origin, '/');
    }

    /**
     * Extract origin from a full URL (like Referer header).
     */
    private function extractOrigin(string $url): ?string
    {
        $parsed = parse_url($url);

        if (! isset($parsed['scheme']) || ! isset($parsed['host'])) {
            return null;
        }

        $origin = $parsed['scheme'].'://'.$parsed['host'];

        if (isset($parsed['port'])) {
            $origin .= ':'.$parsed['port'];
        }

        return $origin;
    }
}
