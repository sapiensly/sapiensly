<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\OrganizationSsoController;
use Illuminate\Support\Facades\Route;

// Email/password authentication routes are handled by Laravel Fortify.
// See config/fortify.php for feature configuration
// and app/Providers/FortifyServiceProvider.php for view customization.

// Single Sign-On entry points are guest-only: an authenticated user has no
// reason to start an OAuth handshake.
Route::middleware('guest')->group(function () {
    // Google social login (personal accounts).
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->name('auth.google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->name('auth.google.callback');

    // Per-organization enterprise OIDC SSO via the org's dedicated URL.
    Route::get('sso/callback', [OrganizationSsoController::class, 'callback'])
        ->name('sso.callback');
    Route::get('sso/{slug}', [OrganizationSsoController::class, 'redirect'])
        ->name('sso.redirect');
});
