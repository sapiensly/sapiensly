<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\User;
use App\Services\Branding\BrandAssetImporter;
use App\Services\Branding\BrandAssetImportFailed;
use App\Services\Site\SiteImportService;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use App\Support\Context\OrganizationContext;
use App\Support\Draft\DraftDiff;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description("Read the organization's own website ONCE and propose BOTH books from it — the Brandbook (icon, logo, brand colour, typeface, theme) and the Contextbook (what the organization does, its offerings, audience, language, vocabulary, canonical links). Use this instead of writing either book from a site you read yourself: it applies the same rails the product does — an unusable accent (grey, near-white, near-black) is refused with a reason, placeholder values and a glossary that merely repeats product names are stripped, and duplicate home-page links are collapsed. It PROPOSES by default and writes nothing: every field comes back labelled `new` (the book has it empty) or `conflict` (the book already says something else). apply=\"new_only\" fills just the empty fields — a conflict is NEVER resolved here, use set_organization_brand / set_organization_context for that once a human has decided. Applying also copies the logo/icon onto our own storage, so the brand does not depend on someone else's server. Organization owners / sysadmins only.")]
class ImportOrganizationBooksTool extends SapiensTool
{
    // No ability gate; owner/sysadmin-gated below, like set_organization_brand.

    /** Brand fields the Brandbook stores, in canonical vocabulary. */
    private const BRAND_FIELDS = [
        'logo_url', 'icon_url', 'logo_dark_url', 'icon_dark_url', 'accent_color', 'logo_bg_color', 'font', 'theme',
    ];

    /** Proposed brand fields whose value is a remote image we must adopt before storing. */
    private const ASSET_FIELDS = ['logo_url' => 'logo', 'icon_url' => 'icon'];

    public function handle(
        Request $request,
        SiteImportService $import,
        BrandAssetImporter $assets,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id === null) {
            return Response::error('This connection is not bound to an organization.');
        }
        if (! $user->hasRole('owner') && ! $user->hasRole('sysadmin')) {
            return Response::error('Only an organization owner or sysadmin can import the organization books.');
        }

        $organization = Organization::find($user->organization_id);
        if ($organization === null) {
            return Response::error('Organization not found.');
        }

        $validated = $request->validate([
            'website' => ['nullable', 'string', 'max:300'],
            'brief' => ['nullable', 'string', 'max:2000'],
            'apply' => ['nullable', 'string', 'in:none,new_only'],
        ]);

        $result = $import->import(
            $organization,
            $validated['website'] ?? null,
            (string) ($validated['brief'] ?? ''),
            $user,
        );

        $apply = ($validated['apply'] ?? 'none') === 'new_only';

        $brand = $apply
            ? $this->applyBrand($organization, $assets, $result['brand']['diff'])
            : ['applied' => [], 'failed' => []];

        $context = $apply
            ? $this->applyContext($organization, $user, $result['context']['diff'])
            : ['applied' => [], 'rejected' => null];

        return Response::json([
            'url' => $result['url'],
            'read' => $result['read'],
            // Why a read produced nothing, in the vocabulary of SiteFetch:
            // ok | no_url | invalid_url | unreachable | not_html | empty.
            'reason' => $result['reason'],
            'applied' => $apply,
            'brand' => [
                'proposal' => $result['brand']['proposal'],
                'applied' => $brand['applied'],
                'conflicts' => self::conflicts($result['brand']['diff']),
                // A logo we could not copy: reported, never silently dropped.
                'assets_failed' => $brand['failed'],
                'palette' => $result['brand']['palette'],
                'notes' => $result['brand']['notes'],
            ],
            'context' => [
                'profile' => $result['context']['profile'],
                'applied' => $context['applied'],
                'conflicts' => self::conflicts($result['context']['diff']),
                'rejected' => $context['rejected'],
                'drafted_from' => $result['context']['sources'],
                'estimated_tokens' => $result['context']['tokens'],
            ],
            // Say it plainly rather than returning an empty draft and letting the
            // caller guess: the page opened, and had no words on it.
            'warning' => $result['read'] && ! $result['context']['site_has_prose']
                ? 'That page carries no readable text — its content is most likely rendered by JavaScript, which we do not run. '
                    .'The Brandbook signals in the markup were still read. To get a Contextbook, call again with a `brief` '
                    .'describing the business; the website alone cannot produce one.'
                : null,
            'next' => $apply
                ? 'Conflicts were left untouched. Show them to a human and resolve each with set_organization_brand / set_organization_context.'
                : 'Nothing was written. Call again with apply="new_only" to fill the empty fields, or use set_organization_brand / set_organization_context to decide field by field.',
        ]);
    }

    /**
     * Fill the empty Brandbook fields, adopting any remote image first.
     *
     * An image that will not copy costs its own field and nothing else: the
     * colours and the typeface from the same reading still land.
     *
     * @param  list<array{field: string, status: string, current: mixed, proposed: mixed}>  $diff
     * @return array{applied: list<string>, failed: list<array{field: string, url: string, message: string}>}
     */
    private function applyBrand(Organization $organization, BrandAssetImporter $assets, array $diff): array
    {
        $additions = [];
        $failed = [];
        $lightLogo = false;

        foreach (self::additions($diff) as $field => $value) {
            if (! in_array($field, self::BRAND_FIELDS, true) || ! is_string($value)) {
                continue;
            }

            $kind = self::ASSET_FIELDS[$field] ?? null;

            if ($kind !== null) {
                try {
                    $asset = $assets->import($organization, $kind, $value);
                } catch (BrandAssetImportFailed $e) {
                    $failed[] = ['field' => $field, 'url' => $value, 'message' => $e->getMessage()];

                    continue;
                }

                $lightLogo = $lightLogo || ($kind === 'logo' && $asset->tone->isLight());
                $value = $asset->url;
            }

            $additions[$field] = $value;
        }

        // Half the web draws its logo for a dark header, so what the markup
        // publishes is the light-ink one — and `logo_url` is the field LIGHT
        // surfaces read, with no fallback to the dark variant. Left alone it is
        // a white mark on a white header. Filling the backdrop is the same rule
        // as every other field here: it was empty, so it is safe to fill.
        if ($lightLogo && ($organization->brandbook()->logoBgColor === null)) {
            $additions['logo_bg_color'] = ColorPalette::backdrop($organization->brandbook()->effectiveAccent());
        }

        if ($additions !== []) {
            $organization->brand = OrganizationBrand::fromArray(
                array_merge($organization->brand ?? [], $additions),
            )->toArray();
            $organization->save();
        }

        return ['applied' => array_keys($additions), 'failed' => $failed];
    }

    /**
     * Fill the empty Contextbook fields, subject to the same token cap the web
     * form and set_organization_context enforce — this block is billed on every
     * model call in the organization, so it is refused whole rather than
     * truncated mid-sentence.
     *
     * @param  list<array{field: string, status: string, current: mixed, proposed: mixed}>  $diff
     * @return array{applied: list<string>, rejected: string|null}
     */
    private function applyContext(Organization $organization, User $user, array $diff): array
    {
        $additions = self::additions($diff);

        if ($additions === []) {
            return ['applied' => [], 'rejected' => null];
        }

        $row = OrganizationAiContext::firstOrNew(['organization_id' => $organization->id])
            ->setRelation('organization', $organization);

        $context = OrganizationContext::fromArray(array_merge($row->profile ?? [], $additions));
        $tokens = $context->estimatedTokens($organization->name);

        if ($tokens > OrganizationContext::MAX_TOKENS) {
            return ['applied' => [], 'rejected' => "The drafted Contextbook would render to {$tokens} tokens, over the "
                .OrganizationContext::MAX_TOKENS.' cap, so none of it was applied. Trim the draft with '
                .'set_organization_context, or move the long-form material into a Knowledge Base.'];
        }

        $row->fill([
            'profile' => $context->toArray(),
            'updated_by_id' => $user->id,
        ])->recompile()->save();

        return ['applied' => array_keys($additions), 'rejected' => null];
    }

    /**
     * The fields safe to fill without asking — the book has them empty.
     *
     * @param  list<array{field: string, status: string, current: mixed, proposed: mixed}>  $diff
     * @return array<string, mixed>
     */
    private static function additions(array $diff): array
    {
        $additions = [];

        foreach ($diff as $entry) {
            if ($entry['status'] === DraftDiff::NEW) {
                $additions[$entry['field']] = $entry['proposed'];
            }
        }

        return $additions;
    }

    /**
     * What the site says that the book contradicts. Both sides travel, because
     * the caller's job here is to show a human the choice, not to make it.
     *
     * @param  list<array{field: string, status: string, current: mixed, proposed: mixed}>  $diff
     * @return list<array{field: string, current: mixed, proposed: mixed}>
     */
    private static function conflicts(array $diff): array
    {
        return array_values(array_map(
            fn (array $entry) => [
                'field' => $entry['field'],
                'current' => $entry['current'],
                'proposed' => $entry['proposed'],
            ],
            array_filter($diff, fn (array $entry) => $entry['status'] === DraftDiff::CONFLICT),
        ));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description(
                "The organization's website. A bare host is fine (acme.com) — it is normalized before fetching. "
                .'Defaults to nothing; pass the Contextbook website (get_organization_context) when you have no other.',
            ),
            'brief' => $schema->string()->description(
                'Optional: one or two sentences about the business, used ALONGSIDE the page when drafting the '
                .'Contextbook. The only material when no website is readable. Does not affect the Brandbook.',
            ),
            'apply' => $schema->string()->description(
                'What to write. "none" (default) proposes only and writes nothing. "new_only" fills the fields each '
                .'book has empty and leaves every conflict untouched for a human.',
            ),
        ];
    }
}
