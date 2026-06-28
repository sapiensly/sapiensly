<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use App\Models\Organization;
use App\Models\User;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Set the organization's Brandbook (logo, icon, colours, font, theme). Only the fields you pass are changed; pass null to clear one. New apps/chatbots are seeded with this brand and existing surfaces follow it live where they didn't override. Organization owners / sysadmins only.")]
class SetOrganizationBrandTool extends SapiensTool
{
    // No ability gate; owner/sysadmin-gated below, like the web Brandbook page.

    /** Canonical brand fields this tool accepts. */
    private const FIELDS = [
        'logo_url', 'icon_url', 'icon_emoji', 'accent_color', 'font', 'theme',
    ];

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id === null) {
            return Response::error('This connection is not bound to an organization.');
        }
        if (! $user->hasRole('owner') && ! $user->hasRole('sysadmin')) {
            return Response::error('Only an organization owner or sysadmin can edit the brand.');
        }

        $validated = $request->validate([
            'logo_url' => ['nullable', 'string', 'max:2000'],
            'icon_url' => ['nullable', 'string', 'max:2000'],
            'icon_emoji' => ['nullable', 'string', 'max:16'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font' => ['nullable', Rule::in(OrganizationBrand::FONTS)],
            'theme' => ['nullable', Rule::in(OrganizationBrand::THEMES)],
        ]);

        $organization = Organization::find($user->organization_id);
        if ($organization === null) {
            return Response::error('Organization not found.');
        }

        // Merge only the keys present in the call over the stored brand, then
        // normalize — a partial set leaves untouched fields intact.
        $incoming = array_intersect_key($validated, array_flip(self::FIELDS));
        $merged = array_merge($organization->brand ?? [], $incoming);
        $organization->brand = OrganizationBrand::fromArray($merged)->toArray();
        $organization->save();

        return Response::json([
            'updated' => true,
            'brand' => $organization->brandbook()->toArray(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'logo_url' => $schema->string()->description('Wide logo image URL (header). Pass null to clear.'),
            'icon_url' => $schema->string()->description('Square icon image URL. Pass null to clear.'),
            'icon_emoji' => $schema->string()->description('Emoji used as the icon when no icon image is set.'),
            'accent_color' => $schema->string()->description('Brand accent colour as #RRGGBB (defaults to the platform blue '.OrganizationBrand::DEFAULT_ACCENT.' when unset).'),
            'font' => $schema->string()->enum(OrganizationBrand::FONTS)->description('Default font family.'),
            'theme' => $schema->string()->enum(OrganizationBrand::THEMES)->description('Default colour palette (light/dark).'),
        ];
    }
}
