<?php

namespace App\Http\Middleware;

use App\Support\Apps\EnvironmentContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request in the environment the viewer chose.
 *
 * An environment is a MODE somebody is in, not a parameter of one page. Carried
 * in the URL it would have to be threaded through every link, every form post
 * and every action the runtime fires — and the first one that forgot would drop
 * somebody back into production mid-task without saying so, which is the exact
 * accident this whole feature exists to prevent.
 *
 * So it is remembered, per app: `?env=demo` switches, and every request after
 * it stays there until the viewer switches back. Per app rather than globally
 * because somebody demoing one app is not demoing the others.
 *
 * Applied to the runtime AND to the action endpoint, or a form submitted from
 * the sandbox would write its record into production.
 */
class BindAppEnvironment
{
    public const SESSION_PREFIX = 'app_env.';

    public function handle(Request $request, Closure $next): Response
    {
        $appSlug = (string) $request->route('app_slug');

        if ($appSlug !== '') {
            $key = self::SESSION_PREFIX.$appSlug;

            $asked = $request->query('env');
            if (is_string($asked) && in_array($asked, EnvironmentContext::ALL, true)) {
                $request->session()->put($key, $asked);
            }

            app(EnvironmentContext::class)->set($request->session()->get($key));

            // A request may ask to be treated as the SANDBOX, and only that.
            //
            // The builder preview needs it: it reads the demo on purpose, and
            // without this a form submitted inside the preview would write its
            // record into production — a surface that reads one side and writes
            // the other is worse than one that gets both wrong.
            //
            // Narrowing only, and that asymmetry is the whole safety of it. A
            // caller can put itself in the sandbox, which costs nothing if it
            // was lying; it can never take itself OUT, because leaving is the
            // one direction where being wrong reaches real records.
            if ($request->input('environment') === EnvironmentContext::DEMO) {
                app(EnvironmentContext::class)->set(EnvironmentContext::DEMO);
            }
        }

        return $next($request);
    }
}
