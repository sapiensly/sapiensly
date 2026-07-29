<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\AuthorizesOrganizationAdmin;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Services\Branding\BrandAssetImporter;
use App\Services\Branding\BrandAssetImportFailed;
use App\Services\Branding\PaletteProposalService;
use App\Services\Site\SiteImportService;
use App\Services\Storage\TenantStorage;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use App\Support\Storage\TenantPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manages the organization Brandbook (logo, icon, colours, font) — the central
 * theme every customizable surface inherits. Gated to organization admins (the
 * owner or a sysadmin), mirroring the rest of the organization settings.
 */
class OrganizationBrandController extends Controller
{
    use AuthorizesOrganizationAdmin;

    /** The brand fields accepted from the form, in canonical (stored) vocabulary. */
    private const FIELDS = [
        'logo_url', 'icon_url', 'logo_dark_url', 'icon_dark_url', 'accent_color', 'logo_bg_color', 'font', 'theme',
    ];

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

    public function show(Request $request, SiteImportService $import): Response
    {
        $organization = $this->authorizeOrganization($request, 'the brand');
        $brand = $organization->brandbook();

        return Inertia::render('settings/OrganizationBrand', [
            'brand' => $brand->toArray(),
            // The palette currently in effect (derived from the active accent) so
            // the page can show it without waiting for AI proposals.
            'palette' => ColorPalette::fromAccent($brand->effectiveAccent()),
            // The site import starts from the website the Contextbook already
            // knows: the Brandbook has no URL of its own, and asking the admin to
            // retype the address they typed on the other page is asking twice for
            // the same fact.
            'website' => $this->knownWebsite($organization),
            'lastImport' => $import->lastImport(),
        ]);
    }

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

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->authorizeOrganization($request, 'the brand');

        $validated = $request->validate([
            'logo_url' => ['nullable', 'string', 'max:2000'],
            'icon_url' => ['nullable', 'string', 'max:2000'],
            'logo_dark_url' => ['nullable', 'string', 'max:2000'],
            'icon_dark_url' => ['nullable', 'string', 'max:2000'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_bg_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font' => ['nullable', Rule::in(OrganizationBrand::FONTS)],
            'theme' => ['nullable', Rule::in(OrganizationBrand::THEMES)],
        ]);

        // Merge only the submitted keys over the stored brand, then normalize, so a
        // partial update leaves untouched fields intact and a cleared field clears.
        $incoming = array_intersect_key($validated, array_flip(self::FIELDS));
        $merged = array_merge($organization->brand ?? [], $incoming);
        $organization->brand = OrganizationBrand::fromArray($merged)->toArray();
        $organization->save();

        return back()->with('success', __('Brandbook updated.'));
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
            $url = $assets->import($organization, $validated['kind'], $validated['url']);
        } catch (BrandAssetImportFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['kind' => $validated['kind'], 'url' => $url]);
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

    /** The organization's website as the Contextbook records it, if it has one. */
    private function knownWebsite(Organization $organization): ?string
    {
        $profile = OrganizationAiContext::firstOrNew(['organization_id' => $organization->id])->profile ?? [];
        $website = $profile['website'] ?? null;

        return is_string($website) && $website !== '' ? $website : null;
    }
}
