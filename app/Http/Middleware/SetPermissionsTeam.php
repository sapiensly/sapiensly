<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPermissionsTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            setPermissionsTeamId($request->user()->organization_id);
        }

        return $next($request);
    }
}
