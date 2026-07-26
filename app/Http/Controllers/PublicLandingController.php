<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Landing\LandingRuntimeProps;
use App\Services\Manifest\AppManifestService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

        // Shared with the signed headless-render route so the shot the design
        // director judges is byte-for-byte the page that ships. publicSurface
        // enables live lead_form submits + the Turnstile key.
        return Inertia::render('runtime/Page', LandingRuntimeProps::build($app, $manifest, publicSurface: true));
    }
}
