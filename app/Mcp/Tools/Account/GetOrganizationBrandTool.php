<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use App\Models\Organization;
use App\Models\User;
use App\Support\Branding\ColorPalette;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("The organization's Brandbook: the central logo, icon, accent colour, font and theme that customizable surfaces (apps, chatbots, slides) inherit — plus the effective accent and the full palette derived from it (the same one live on every app surface as CSS vars --sp-accent-50…900 / --sp-chart-1…6). Call it BEFORE composing any UI so what you generate is on-brand; returns null-valued brand fields when unset (the platform accent then applies).")]
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

        $brand = $organization->brandbook();
        $accent = $brand->effectiveAccent();

        return Response::json([
            ...$brand->toArray(),
            'effective_accent' => $accent,
            'palette' => ColorPalette::fromAccent($accent),
            'usage' => $brand->isEmpty()
                ? 'Brandbook unset — the platform default accent applies. Set it with set_organization_brand so every app, chatbot and deck inherits the brand automatically; the palette above still works via the CSS vars.'
                : 'Apps/chatbots/decks inherit these values fill-the-gaps at runtime. When authoring UI, use the palette CSS vars (--sp-accent-50…900, --sp-accent-soft, --sp-accent-contrast, --sp-chart-1…6) or these hexes for gradients and single_select option colours — never invent brand colours.',
        ]);
    }
}
