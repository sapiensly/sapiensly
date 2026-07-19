<?php

use App\Http\Controllers\AccountSwitchController;
use App\Http\Controllers\PublicLandingController;
use App\Http\Controllers\Settings\OrganizationBrandController;
use App\Http\Controllers\Tools\ToolOAuth2Controller;
use App\Http\Controllers\WidgetAssetController;
use App\Http\Middleware\BindPublicLandingContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Auth::check()
    ? redirect()->route('chat.index')
    : redirect()->route('login'));

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
