<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds `Accept-Language` and `Cookie` to `Vary` on a response whose content was
 * chosen by negotiation — today, a multilingual landing.
 *
 * Without it the first visitor's language is cached and served to everyone
 * behind the same CDN entry. That is the classic failure of header negotiation
 * and it is silent: correct in the browser you test with, wrong for everyone
 * else, and only for as long as the cache lives.
 *
 * It has to be a middleware, and it has to sit OUTSIDE Inertia's, because
 * Inertia's middleware ends with `$response->headers->set('Vary', 'X-Inertia')`
 * — a replace, not an add, on the way out. A header set in the controller is
 * therefore gone by the time the response leaves. Prepended to the `web` group
 * (Inertia's is appended), this one's return path runs last and gets the final
 * word — while still keeping whatever Inertia put there.
 */
class VaryOnNegotiatedLanguage
{
    /** Set by a controller that negotiated: the request attribute we look for. */
    public const ATTRIBUTE = 'varyOnLanguage';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->attributes->get(self::ATTRIBUTE) !== true) {
            return $response;
        }

        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary', ''))));
        foreach (['Accept-Language', 'Cookie'] as $header) {
            if (! in_array($header, $vary, true)) {
                $vary[] = $header;
            }
        }
        $response->headers->set('Vary', implode(', ', $vary));

        return $response;
    }
}
