<?php

use App\Http\Controllers\AccountSwitchController;
use App\Http\Controllers\Integrations\OAuth2\IntegrationOAuth2Controller;
use App\Http\Controllers\WidgetAssetController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Auth::check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'));

// Widget asset route (public, no auth)
Route::get('widget/v1/widget.js', [WidgetAssetController::class, 'script'])
    ->name('widget.script');

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::post('account/switch', AccountSwitchController::class)->name('account.switch');

    // OAuth 2.0 Authorization Code handshake for Integrations. Kept in web.php
    // so the provider redirect lands in the authenticated session that started
    // the handshake — the callback validates state from that same session.
    Route::get('oauth/integrations/{integration}/authorize', [IntegrationOAuth2Controller::class, 'redirect'])
        ->name('integrations.oauth2.authorize');
    Route::get('oauth/integrations/callback', [IntegrationOAuth2Controller::class, 'callback'])
        ->name('integrations.oauth2.callback');
});

require __DIR__.'/settings.php';
require __DIR__.'/agents.php';
require __DIR__.'/standalone-agents.php';
require __DIR__.'/knowledge-bases.php';
require __DIR__.'/tools.php';
require __DIR__.'/documents.php';
require __DIR__.'/chatbots.php';
require __DIR__.'/system.php';
require __DIR__.'/whatsapp.php';
require __DIR__.'/flows.php';
require __DIR__.'/admin.php';
require __DIR__.'/auth.php';
