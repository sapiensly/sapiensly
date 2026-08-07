<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps `no-store` on a runtime page this app is not allowed to leave behind.
 *
 * The decision is `App\Support\Offline\OfflinePolicy`; this is only where it
 * lands. `no-store` is the enforcement point because the service worker already
 * refuses to store a response carrying it — a worker installed last month
 * honours it exactly as well as one installed today, which is a promise no
 * client-side rule can make.
 *
 * A middleware rather than a header set on the controller's return value, for
 * the same reason `VaryOnNegotiatedLanguage` is one: the runtime controller
 * returns an `Inertia\Response`, which has no headers of its own, and the print
 * renderer DELEGATES to that controller and then chains `->with('printing')` on
 * what it gets back. Converting it to an HTTP response in the controller broke
 * exactly that, and the same shape would break the next thing that composes
 * with it. So the controller states the fact on the request and this puts it on
 * the way out.
 */
class NoStoreWhenOfflineIsRefused
{
    /** Set by a controller that resolved the policy: the attribute we look for. */
    public const ATTRIBUTE = 'offlineRefusesCache';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->attributes->get(self::ATTRIBUTE) === true) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
