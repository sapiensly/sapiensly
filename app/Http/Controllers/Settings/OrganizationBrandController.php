<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\AuthorizesOrganizationAdmin;
use App\Models\Organization;
use App\Services\Branding\BrandAssetImporter;
use App\Services\Branding\BrandAssetImportFailed;
use App\Services\Branding\PaletteProposalService;
use App\Services\Storage\TenantStorage;
use App\Support\Branding\AssetTone;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use App\Support\Storage\TenantPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Brandbook's own endpoints: assets (upload, adopt from a site, serve) and
 * palettes (derive one, propose some). Reading and writing the book itself lives
 * on the organization identity screen, which saves it alongside the Contextbook —
 * see {@see OrganizationIdentityController}.
 *
 * Gated to organization admins (the owner or a sysadmin), mirroring the rest of
 * the organization settings.
 */
class OrganizationBrandController extends Controller
{
    use AuthorizesOrganizationAdmin;

    /** Upload extension → served Content-Type. We control the extension on write. */
    private const ASSET_MIME = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
    ];

    public function __construct(private readonly TenantStorage $tenantStorage) {}

    /**
     * Derive the palette for a given accent (the same deterministic expansion
     * every app surface uses), so the Brandbook page can preview the active
     * palette live as the admin edits the accent — without porting the colour
     * maths to JS or waiting on the AI proposal endpoint.
     */
    public function derivePalette(Request $request): JsonResponse
    {
        $this->authorizeOrganization($request, 'the brand');

        $validated = $request->validate([
            'accent' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $accent = $validated['accent'] ?? OrganizationBrand::DEFAULT_ACCENT;

        return response()->json([
            'accent' => $accent,
            'palette' => ColorPalette::fromAccent($accent),
        ]);
    }

    /**
     * Generate accent-colour proposals for the brand (AI when a provider is
     * available, a curated set otherwise). Each proposal ships with the full
     * derived palette so the form can preview exactly what apps will inherit;
     * saving still goes through {@see self::update} like any manual pick.
     */
    public function proposePalettes(Request $request, PaletteProposalService $palettes): JsonResponse
    {
        $organization = $this->authorizeOrganization($request, 'the brand');

        $validated = $request->validate([
            'brief' => ['nullable', 'string', 'max:600'],
        ]);

        return response()->json($palettes->propose(
            trim((string) ($validated['brief'] ?? '')),
            $organization->brandbook()->accentColor,
            $request->user(),
        ));
    }

    /**
     * Copy a remote image (a logo or icon a site proposal found) onto the
     * tenant's disk and return the local URL. Runs only when the user accepts a
     * proposal — proposing must never write bytes anywhere.
     *
     * The rules live in {@see BrandAssetImporter}, shared with the MCP import so
     * both make the same decisions about what may be adopted.
     */
    public function importAsset(Request $request, BrandAssetImporter $assets): JsonResponse
    {
        $organization = $this->authorizeOrganization($request, 'the brand');

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['logo', 'icon'])],
            'url' => ['required', 'string', 'max:2000', 'url'],
        ]);

        try {
            $asset = $assets->import($organization, $validated['kind'], $validated['url']);
        } catch (BrandAssetImportFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'kind' => $validated['kind'],
            'url' => $asset->url,
            ...$this->toneAdvice($organization, $validated['kind'], $asset->tone),
        ]);
    }

    /**
     * What a light-ink logo needs, when we just adopted one.
     *
     * Half the web puts its logo in a dark header, so the logo published in the
     * markup is the light one — and dropped into `logo_url`, the field light
     * surfaces read, it is invisible. There is no rescue in the dark variant
     * ({@see OrganizationBrand::logoFor()} gives light surfaces no fallback), so
     * the answer is the backdrop field that exists for exactly this.
     *
     * Advice, not action: a tone reading is a signal, and nothing here restyles
     * a brand on its own.
     *
     * @return array{needs_backdrop?: bool, suggested_logo_bg_color?: string}
     */
    private function toneAdvice(Organization $organization, string $kind, AssetTone $tone): array
    {
        $brand = $organization->brandbook();

        // Not a header logo, not light ink, or already answered — the rule lives
        // here rather than in the form, so the MCP import and this page agree.
        if (! str_starts_with($kind, 'logo') || ! $tone->isLight() || $brand->logoBgColor !== null) {
            return [];
        }

        return [
            'needs_backdrop' => true,
            'suggested_logo_bg_color' => ColorPalette::backdrop($brand->effectiveAccent()),
        ];
    }

    /**
     * Upload a logo/icon image and return its URL, so the form can store it like a
     * pasted URL (assets and URLs are interchangeable). Bytes land on the tenant's
     * cloud disk (TenantStorage → the org/personal CloudProvider, else global S3),
     * NEVER the local disk — brand assets must survive deploys/scale events and be
     * reachable from every instance. Refuses with 503 if no object storage is
     * wired, rather than silently persisting to ephemeral local storage.
     */
    public function uploadAsset(Request $request): JsonResponse
    {
        $organization = $this->authorizeOrganization($request, 'the brand');

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['logo', 'icon', 'logo_dark', 'icon_dark'])],
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        $uploaded = $request->file('file');
        $ext = strtolower($uploaded->getClientOriginalExtension() ?: 'png');
        $filename = $validated['kind'].'-'.Str::lower((string) Str::ulid()).'.'.$ext;

        // Owner-aware disk (throws → 503 when no S3 is configured). The tenant
        // partition prefix keeps a shared global bucket isolated per org.
        $diskName = $this->tenantStorage->diskNameForOwner($organization->id, null);
        $relativePath = TenantPath::scope($organization->id, null, BrandAssetImporter::ASSET_DIR.'/'.$filename);

        $this->tenantStorage->diskFromName($diskName)->putFileAs(
            dirname($relativePath),
            $uploaded,
            basename($relativePath),
        );

        return response()->json([
            'kind' => $validated['kind'],
            'url' => route('organization.brand.asset.show', [
                'organization' => $organization->id,
                'filename' => $filename,
            ]),
            // A hand-picked file fails the same way a fetched one does: somebody
            // uploading their white logo into "Logo" gets an invisible header,
            // and until now nothing said so. SVG is skipped — reading its tone
            // would mean rendering it, and an upload is already trusted.
            ...($ext === 'svg'
                ? []
                : $this->toneAdvice($organization, $validated['kind'], AssetTone::of(
                    (string) file_get_contents($uploaded->getRealPath()),
                ))),
        ]);
    }

    /**
     * Publicly stream a brand asset from the tenant's cloud disk. Brand logos and
     * icons are public by nature — embedded in app headers, chatbot widgets on
     * external sites, and decks — so this route is unauthenticated. The disk is
     * re-resolved from the owning org (no disk name is trusted from the URL), and
     * the filename is regex-constrained on the route so the reconstructed path can
     * never escape the org's own brand prefix.
     */
    public function showAsset(Organization $organization, string $filename): StreamedResponse
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! isset(self::ASSET_MIME[$ext])) {
            throw new NotFoundHttpException('Asset not found.');
        }

        $relativePath = TenantPath::scope($organization->id, null, BrandAssetImporter::ASSET_DIR.'/'.$filename);

        try {
            $disk = $this->tenantStorage->diskFromName(
                $this->tenantStorage->diskNameForOwner($organization->id, null),
            );
        } catch (\Throwable) {
            throw new NotFoundHttpException('Asset not found.');
        }

        if (! $disk->exists($relativePath)) {
            throw new NotFoundHttpException('Asset not found.');
        }

        return $disk->response($relativePath, $filename, [
            'Content-Type' => self::ASSET_MIME[$ext],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
