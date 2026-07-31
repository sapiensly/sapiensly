<?php

namespace App\Http\Controllers\Settings\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The gate every organization-wide settings screen shares: what is saved there
 * changes the behaviour of every app, agent and chatbot in the organization at
 * once, so only the owner or a sysadmin may touch it.
 *
 * One implementation because three copies of an authorization check are three
 * places to forget to change it.
 */
trait AuthorizesOrganizationAdmin
{
    /** The acting user's organization, or abort. */
    protected function authorizeOrganization(Request $request, string $subject = 'these settings'): Organization
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->organization_id !== null && ($user->hasRole('owner') || $user->isSysAdmin()),
            403,
            "Only an organization administrator can manage {$subject}.",
        );

        return $user->organization;
    }
}
