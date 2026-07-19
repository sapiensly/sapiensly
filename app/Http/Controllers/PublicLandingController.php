<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Landing\PublicLandingBlocks;
use App\Services\Manifest\AppManifestService;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use App\Support\Css\ScopedAppCss;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders a PUBLISHED landing for anonymous visitors — the public sibling of
 * AppRuntimeController, reduced to what a marketing page needs and hardened for
 * guests. BindPublicLandingContext already resolved + gated the app and bound
 * the owner's tenant scope. Differences from the authenticated runtime:
 *
 *  - Blocks pass the PublicLandingBlocks allowlist (presentational only, no
 *    tenant data, visibility-ruled blocks dropped, action sequences stripped).
 *  - blockData ships EAGERLY as an empty map (nothing data-backed survives the
 *    filter), so the page is complete on first paint — SSR/SEO friendly, no
 *    deferred second request.
 *  - <head> metadata comes from settings.seo (title/description/og_image),
 *    falling back to the app's name/description.
 *  - No agent panel yet — the runtime agent endpoint is session-authenticated;
 *    the public conversion loop is the lead-capture slice.
 */
class PublicLandingController extends Controller
{
    public function __construct(
        private readonly AppManifestService $manifestService,
    ) {}

    public function __invoke(Request $request): Response
    {
        /** @var App $app */
        $app = $request->attributes->get('publicLandingApp');

        $manifest = $this->manifestService->getActiveManifest($app);
        $pages = $manifest['pages'] ?? [];
        if ($manifest === null || $pages === []) {
            throw new NotFoundHttpException;
        }

        // A landing is its first page. (Multi-page landings can grow a page
        // param later; anchors within the page cover today's navigation.)
        $page = $pages[0];
        $page['blocks'] = PublicLandingBlocks::filter($page['blocks'] ?? []);

        // Effective settings = manifest settings + org Brandbook fallback + the
        // derived palette, exactly like the authenticated runtime — the public
        // page must look identical to the preview that shipped it.
        $settings = $app->organization !== null
            ? $app->organization->brandbook()->applyToAppSettings($manifest['settings'] ?? [])
            : ($manifest['settings'] ?? []);
        $settings['palette'] = ColorPalette::fromAccent(
            $settings['accent'] ?? OrganizationBrand::DEFAULT_ACCENT,
            (string) ($settings['palette_mode'] ?? 'brand'),
        );

        $seo = is_array($settings['seo'] ?? null) ? $settings['seo'] : [];

        return Inertia::render('runtime/Page', [
            'app' => [
                'id' => $app->id,
                // The public slug is this page's identity; the tenant slug stays
                // server-side (it would leak the org's internal naming).
                'slug' => $app->public_slug,
                'name' => $app->name,
                'icon' => $app->icon,
                'color' => $app->color,
                'kind' => $app->kind,
            ],
            'manifest' => [
                'navigation' => null,
                'pages' => [[
                    'id' => $page['id'],
                    'slug' => $page['slug'],
                    'name' => $page['name'],
                    'icon' => $page['icon'] ?? null,
                    'nav' => true,
                ]],
                'settings' => $settings,
                // Presentational blocks format nothing record-shaped; don't ship
                // the tenant's data dictionary to anonymous visitors.
                'objects' => [],
                'agent' => null,
            ],
            'page' => $page,
            'activeSlug' => $page['slug'],
            // EAGER empty map (not Inertia::defer): nothing data-backed survives
            // the public filter, and an SSR/crawler render must be complete on
            // the first response.
            'blockData' => (object) [],
            'params' => (object) [],
            'customCss' => ScopedAppCss::compile($settings['custom_css'] ?? null),
            // Head metadata for the landing (title/description/og image).
            'seo' => [
                'title' => (string) ($seo['title'] ?? $app->name),
                'description' => (string) ($seo['description'] ?? ($app->description ?? '')),
                'og_image' => (string) ($seo['og_image'] ?? ''),
            ],
            // Enables live lead_form submits (the preview renders them disabled)
            // and passes the Turnstile site key when bot protection is configured.
            'publicSurface' => true,
            'turnstileSiteKey' => config('services.turnstile.site_key'),
        ]);
    }
}
