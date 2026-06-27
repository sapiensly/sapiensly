<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages the organization Brandbook (logo, icon, colours, font) — the central
 * theme every customizable surface inherits. Gated to organization admins (the
 * owner or a sysadmin), mirroring the rest of the organization settings.
 */
class OrganizationBrandController extends Controller
{
    /** The brand fields accepted from the form, in canonical (stored) vocabulary. */
    private const FIELDS = [
        'logo_url', 'icon_url', 'icon_emoji',
        'primary_color', 'background_color', 'text_color', 'font', 'theme',
    ];

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

        $hex = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $validated = $request->validate([
            'logo_url' => ['nullable', 'string', 'max:2000'],
            'icon_url' => ['nullable', 'string', 'max:2000'],
            'icon_emoji' => ['nullable', 'string', 'max:16'],
            'primary_color' => $hex,
            'background_color' => $hex,
            'text_color' => $hex,
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
     * Upload a logo/icon image to the public disk and return its URL, so the form
     * can store it like a pasted URL (assets and URLs are interchangeable).
     */
    public function uploadAsset(Request $request): JsonResponse
    {
        $organization = $this->authorizeOrganization($request);

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['logo', 'icon'])],
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        $path = $request->file('file')->store("org-brand/{$organization->id}", 'public');

        return response()->json([
            'kind' => $validated['kind'],
            'url' => Storage::disk('public')->url($path),
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
