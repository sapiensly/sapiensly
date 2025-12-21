<?php

namespace App\Http\Middleware;

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

        // If no allowed origins configured, allow all
        $allowedOrigins = $chatbot->allowed_origins ?? [];

        if (empty($allowedOrigins)) {
            return $next($request);
        }

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
