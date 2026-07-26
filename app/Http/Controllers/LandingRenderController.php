<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\User;
use App\Services\Landing\LandingRuntimeProps;
use App\Services\Manifest\AppManifestService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders an owner's landing for a HEADLESS screenshot (HeadlessLandingShot →
 * Browsershot). Unlike PublicLandingController this is NOT gated on publish: it
 * serves any of the owner's landings (a draft mid-build too), which is the whole
 * point — the design gate and the render_landing MCP tool need pixels BEFORE a
 * landing ships.
 *
 * Signed-URL only (middleware 'signed'), no session: the tenant scope comes from
 * the signature's `uid`/`org` params, exactly like SlidesController::print. Only
 * server code that already resolved a visible app can mint a valid URL, so the
 * signature IS the authorization. The same LandingRuntimeProps builder as the
 * public page renders the identical page; the shot is captured with
 * prefers-reduced-motion forced, so every data-sp-reveal is at its final state.
 */
class LandingRenderController extends Controller
{
    public function __construct(
        private readonly AppManifestService $manifestService,
    ) {}

    public function __invoke(Request $request, string $app): Response
    {
        $owner = User::find((int) $request->query('uid'));
        abort_if($owner === null, 404);

        $org = $request->query('org') !== null ? (string) $request->query('org') : null;
        abort_if($org !== ($owner->organization_id ?: null), 404);

        $ctx = app(TenantContext::class);
        $previousOrg = $ctx->organizationId();
        $previousUid = $ctx->userId();
        $ctx->set($owner->organization_id, $owner->id);

        try {
            /** @var App $appModel */
            $appModel = App::forAccountContext($owner)->findOrFail($app);
            $manifest = $this->manifestService->getActiveManifest($appModel);

            // publicSurface:false — this is a preview/critique shot, not the live
            // page: the lead_form renders styled-but-inert (no public endpoint to
            // post to on an unpublished draft), and the .sp-lead-form design the
            // director judges is identical.
            return Inertia::render('runtime/Page', LandingRuntimeProps::build($appModel, $manifest, publicSurface: false));
        } finally {
            $ctx->set($previousOrg, $previousUid);
        }
    }
}
