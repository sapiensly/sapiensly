<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the platform admin area on the sysadmin role.
 *
 * Replaces spatie's `role:sysadmin` for these routes, which does not work here:
 * that check is scoped to the current permissions TEAM, SetPermissionsTeam pins
 * the team to the user's active organization, and the sysadmin role is assigned
 * globally with a null team. The result is that a sysadmin who happens to belong
 * to an organization is refused their own admin area — invisible today only
 * because the seeded sysadmins have no organization.
 *
 * {@see User::isSysAdmin()} evaluates the assignment against the null team it
 * was actually made under, so the answer no longer depends on which
 * organization the person is currently looking at.
 */
class EnsureUserIsSysAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isSysAdmin(), 403);

        return $next($request);
    }
}
