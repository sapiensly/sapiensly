<?php

use App\Http\Controllers\Settings\OrganizationBrandController;
use App\Http\Controllers\Settings\OrganizationContextController;
use App\Http\Controllers\Settings\OrganizationController;
use App\Http\Controllers\Settings\OrganizationIdentityController;
use App\Http\Controllers\Settings\OrganizationSiteImportController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SsoConnectionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/organization', [OrganizationController::class, 'show'])->name('organization.show');
    Route::get('settings/organization/create', [OrganizationController::class, 'create'])->name('organization.create');
    Route::post('settings/organization', [OrganizationController::class, 'store'])->name('organization.store');
    Route::patch('settings/organization/retention', [OrganizationController::class, 'updateRetention'])
        ->name('organization.retention');
    Route::delete('settings/organization', [OrganizationController::class, 'destroy'])->name('organization.destroy');
    Route::post('settings/organization/invite', [OrganizationController::class, 'invite'])->name('organization.invite');

    // The organization's identity: the Brandbook (logo/icon/colours/font every
    // customizable surface inherits) and the Contextbook (the business knowledge
    // every AI interaction is grounded in) as two tabs over the general facts both
    // are built from, saved in one write. Admin-gated inside the controller.
    Route::get('settings/organization/identity', [OrganizationIdentityController::class, 'show'])->name('organization.identity.show');
    Route::put('settings/organization/identity', [OrganizationIdentityController::class, 'update'])->name('organization.identity.update');

    // Each book used to be a page of its own; the addresses still work and land on
    // their tab, because they are in people's history and in old links.
    Route::get('settings/organization/brand', fn () => redirect('/settings/organization/identity?tab=brand'))
        ->name('organization.brand.show');
    Route::get('settings/organization/context', fn () => redirect('/settings/organization/identity?tab=context'))
        ->name('organization.context.show');

    // Brandbook assets and palettes.
    Route::post('settings/organization/brand/asset', [OrganizationBrandController::class, 'uploadAsset'])->name('organization.brand.asset');
    Route::post('settings/organization/brand/asset/import', [OrganizationBrandController::class, 'importAsset'])->name('organization.brand.asset.import');
    Route::post('settings/organization/brand/palette-proposals', [OrganizationBrandController::class, 'proposePalettes'])->name('organization.brand.palettes');
    Route::post('settings/organization/brand/palette', [OrganizationBrandController::class, 'derivePalette'])->name('organization.brand.palette.derive');

    // The Contextbook block as the models will read it, for form state that is not
    // saved yet (the cost is shown before anything is stored).
    Route::post('settings/organization/context/preview', [OrganizationContextController::class, 'preview'])->name('organization.context.preview');

    // One reading of the organization's website, proposing BOTH books, so the URL
    // is typed once, the page is downloaded once, and the draft is paid for once.
    Route::post('settings/organization/site-import', [OrganizationSiteImportController::class, 'store'])->name('organization.site-import');

    Route::get('settings/sso', [SsoConnectionController::class, 'show'])->name('sso.show');
    Route::put('settings/sso', [SsoConnectionController::class, 'update'])->name('sso.update');
    Route::post('settings/sso/discover', [SsoConnectionController::class, 'discover'])->name('sso.discover');
});
