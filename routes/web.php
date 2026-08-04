<?php

use App\Http\Controllers\AccountSwitchController;
use App\Http\Controllers\AppPrintController;
use App\Http\Controllers\LandingRenderController;
use App\Http\Controllers\PortalAuthController;
use App\Http\Controllers\PublicAppActionController;
use App\Http\Controllers\PublicAppController;
use App\Http\Controllers\PublicAppFileController;
use App\Http\Controllers\PublicLandingController;
use App\Http\Controllers\PublicLeadController;
use App\Http\Controllers\Settings\OrganizationBrandController;
use App\Http\Controllers\Tools\ToolOAuth2Controller;
use App\Http\Controllers\WidgetAssetController;
use App\Http\Middleware\BindPublicAppContext;
use App\Http\Middleware\BindPublicLandingContext;
use App\Services\Landing\CustomDomainService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// The root serves two worlds by Host header: on a tenant's ACTIVE custom
// domain it renders their published landing (Cloudflare for SaaS terminates
// TLS and forwards here); on the platform hosts it keeps the login/chat
// redirect. The lead form keeps working on custom domains because the
// /l/{slug}/lead routes are host-agnostic.
Route::get('/', function (Request $request) {
    $app = app(CustomDomainService::class)->appForHost($request->getHost());
    if ($app !== null) {
        app(TenantContext::class)->set($app->organization_id, $app->user_id);
        $request->attributes->set('publicLandingApp', $app);

        return app(PublicLandingController::class)($request);
    }

    return Auth::check()
        ? redirect()->route('chat.index')
        : redirect()->route('login');
});

// Widget asset route (public, no auth)
Route::get('widget/v1/widget.js', [WidgetAssetController::class, 'script'])
    ->name('widget.script');

// Brand logo/icon serving (public, no auth) — brand assets are embedded in app
// headers, public chatbot widgets and decks. Streams from the tenant cloud disk;
// the filename is constrained so it can't escape the org's brand prefix.
Route::get('brand-asset/{organization}/{filename}', [OrganizationBrandController::class, 'showAsset'])
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('organization.brand.asset.show');

// Published landing pages (public, no auth). BindPublicLandingContext resolves
// the app by its globally-unique public slug, 404s anything unpublished or
// non-landing, and binds the owner's tenant scope. Throttled — this is an
// internet-facing surface.
Route::get('l/{public_slug}', PublicLandingController::class)
    ->where('public_slug', '[a-z0-9][a-z0-9_-]*')
    ->middleware([BindPublicLandingContext::class, 'throttle:120,1'])
    ->name('landing.public');

// Public lead capture: the ONE write a guest gets. Same publish gate + owner
// tenant binding as the page; tighter throttle (it writes), honeypot +
// optional Turnstile inside the controller.
Route::post('l/{public_slug}/lead', PublicLeadController::class)
    ->where('public_slug', '[a-z0-9][a-z0-9_-]*')
    ->middleware([BindPublicLandingContext::class, 'throttle:10,1'])
    ->name('landing.public.lead');

// Public app PORTALS (public, no auth). BindPublicAppContext resolves the app
// by its globally-unique public slug and 404s anything unpublished, landing-
// shaped, or whose active manifest no longer opens the portal — closing it in
// the manifest takes the URL down the same second, without a second act.
// Throttled — this is an internet-facing surface.
Route::get('a/{public_slug}/{page_slug?}', PublicAppController::class)
    ->where('public_slug', '[a-z0-9][a-z0-9_-]*')
    ->where('page_slug', '[a-z][a-z0-9_]*')
    ->middleware([BindPublicAppContext::class, 'throttle:120,1'])
    ->name('portal.public');

// The portal's write path. Same publish gate; tighter throttle (it writes), and
// permissions.public.allow_writes + the visitor role's per-object grants +
// honeypot/Turnstile inside the controller.
Route::post('a/{public_slug}/actions', PublicAppActionController::class)
    ->where('public_slug', '[a-z0-9][a-z0-9_-]*')
    ->middleware([BindPublicAppContext::class, 'throttle:20,1'])
    ->name('portal.public.actions');

// Portal sign-in. The request endpoint answers identically whatever happened —
// a portal that replied differently for a known address would be an account
// oracle, and anyone may ask.
Route::post('a/{public_slug}/auth/request', [PortalAuthController::class, 'request'])
    ->where('public_slug', '[a-z0-9][a-z0-9_-]*')
    ->middleware([BindPublicAppContext::class, 'throttle:10,1'])
    ->name('portal.public.auth.request');

Route::get('a/{public_slug}/auth/{token}', [PortalAuthController::class, 'callback'])
    ->where('public_slug', '[a-z0-9][a-z0-9_-]*')
    ->where('token', '[a-f0-9]{64}')
    ->middleware([BindPublicAppContext::class, 'throttle:20,1'])
    ->name('portal.public.auth.callback');

Route::post('a/{public_slug}/auth/logout', [PortalAuthController::class, 'logout'])
    ->where('public_slug', '[a-z0-9][a-z0-9_-]*')
    ->middleware([BindPublicAppContext::class])
    ->name('portal.public.auth.logout');

// Portal file upload/serve. Writable storage reachable by anyone is the most
// abusable surface here, so the controller bounds it hard: writes must be on,
// 10MB, an extension allowlist, and everything downloads rather than renders.
Route::post('a/{public_slug}/uploads', [PublicAppFileController::class, 'upload'])
    ->where('public_slug', '[a-z0-9][a-z0-9_-]*')
    ->middleware([BindPublicAppContext::class, 'throttle:10,1'])
    ->name('portal.public.uploads');

Route::get('a/{public_slug}/files/{file_id}', [PublicAppFileController::class, 'show'])
    ->where('public_slug', '[a-z0-9][a-z0-9_-]*')
    ->where('file_id', 'fil_[a-z0-9]+')
    ->middleware([BindPublicAppContext::class, 'throttle:120,1'])
    ->name('portal.public.files');

// Signed headless-render of an owner's landing (published OR draft) for
// HeadlessLandingShot's Browsershot capture. No session — the tenant scope
// travels in the signature's uid/org params (see LandingRenderController).
Route::get('apps/{app}/landing-render', LandingRenderController::class)
    ->middleware('signed')
    ->name('landing.render');

// Signed headless-render of a runtime page for its PDF. Same shape and same
// reason: Chromium arrives with no cookies, so the signature carries the tenant
// scope. It renders the page through AppRuntimeController itself, so what
// prints is what the owner would see (see AppPrintController).
Route::get('apps/{app_slug}/print/{page_slug}', [AppPrintController::class, 'render'])
    ->middleware('signed')
    ->where('app_slug', '[a-z][a-z0-9_]*')
    ->where('page_slug', '[a-z][a-z0-9_]*')
    ->name('apps.runtime.print');

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::post('account/switch', AccountSwitchController::class)->name('account.switch');

    // Per-user OAuth 2.0 Authorization Code handshake for MCP tools. Kept in
    // web.php so the provider redirect lands in the authenticated session that
    // started the handshake — the callback validates state from that session
    // and stores the tokens against the current user (not the shared
    // integration). The callback path is kept stable because it is the
    // redirect_uri registered with providers.
    Route::get('tools/{tool}/oauth2/authorize', [ToolOAuth2Controller::class, 'redirect'])
        ->name('tools.oauth2.authorize');
    Route::get('oauth/integrations/callback', [ToolOAuth2Controller::class, 'callback'])
        ->name('integrations.oauth2.callback');
});

require __DIR__.'/chat.php';
require __DIR__.'/playground.php';
require __DIR__.'/debate.php';
require __DIR__.'/settings.php';
require __DIR__.'/standalone-agents.php';
require __DIR__.'/knowledge-bases.php';
require __DIR__.'/tools.php';
require __DIR__.'/documents.php';
require __DIR__.'/slides.php';
require __DIR__.'/chatbots.php';
require __DIR__.'/system.php';
require __DIR__.'/whatsapp.php';
require __DIR__.'/bot-flows.php';
require __DIR__.'/apps.php';
require __DIR__.'/admin.php';
require __DIR__.'/auth.php';
