<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use App\Models\Organization;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("The organization's Brandbook: the central logo, icon, colours (primary/background/text), font and theme that customizable surfaces (apps, chatbots) inherit. Use it to theme what you build on-brand. Returns null-valued fields when unset.")]
class GetOrganizationBrandTool extends SapiensTool
{
    // No ability gate: the brand is org-level theming info any member may read.

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id === null) {
            return Response::error('This connection is not bound to an organization.');
        }

        $organization = Organization::find($user->organization_id);
        if ($organization === null) {
            return Response::error('Organization not found.');
        }

        return Response::json($organization->brandbook()->toArray());
    }
}
