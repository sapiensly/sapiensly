<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Branding\PaletteProposalService;
use App\Services\Storage\TenantStorage;
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
    /** The brand fields accepted from the form, in canonical (stored) vocabulary. */
    private const FIELDS = [
        'logo_url', 'icon_url', 'icon_emoji', 'accent_color', 'logo_bg_color', 'font', 'theme',
    ];

    /** Content storage prefix (under the tenant partition) for brand assets. */
    private const ASSET_DIR = 'org-brand';

    /** Upload extension → served Content-Type. We control the extension on write. */
    private const ASSET_MIME = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    public function __construct(private readonly TenantStorage $tenantStorage) {}

    public function show(Request $request): Response
    {
        $organization = $this->authorizeOrganization($request);

        return Inertia::render('settings/OrganizationBrand', [
            'brand' => $organization->brandbook()->toArray(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->authorizeOrganization($request);

        $validated = $request->validate([
            'logo_url' => ['nullable', 'string', 'max:2000'],
            'icon_url' => ['nullable', 'string', 'max:2000'],
            'icon_emoji' => ['nullable', 'string', 'max:16'],
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
        $organization = $this->authorizeOrganization($request);

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
     * Upload a logo/icon image and return its URL, so the form can store it like a
     * pasted URL (assets and URLs are interchangeable). Bytes land on the tenant's
     * cloud disk (TenantStorage → the org/personal CloudProvider, else global S3),
     * NEVER the local disk — brand assets must survive deploys/scale events and be
     * reachable from every instance. Refuses with 503 if no object storage is
     * wired, rather than silently persisting to ephemeral local storage.
     */
    public function uploadAsset(Request $request): JsonResponse
    {
        $organization = $this->authorizeOrganization($request);

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['logo', 'icon'])],
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        $uploaded = $request->file('file');
        $ext = strtolower($uploaded->getClientOriginalExtension() ?: 'png');
        $filename = $validated['kind'].'-'.Str::lower((string) Str::ulid()).'.'.$ext;

        // Owner-aware disk (throws → 503 when no S3 is configured). The tenant
        // partition prefix keeps a shared global bucket isolated per org.
        $diskName = $this->tenantStorage->diskNameForOwner($organization->id, null);
        $relativePath = TenantPath::scope($organization->id, null, self::ASSET_DIR.'/'.$filename);

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

        $relativePath = TenantPath::scope($organization->id, null, self::ASSET_DIR.'/'.$filename);

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

    /**
     * The acting user's organization, or abort — only an org owner / sysadmin may
     * edit the brand.
     */
    private function authorizeOrganization(Request $request): Organization
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->organization_id !== null && ($user->hasRole('owner') || $user->hasRole('sysadmin')),
            403,
            'Only an organization administrator can manage the brand.',
        );

        return $user->organization;
    }
}
