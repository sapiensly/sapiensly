<?php

use App\Http\Controllers\Settings\OrganizationBrandController;
use App\Http\Controllers\Settings\OrganizationContextController;
use App\Http\Controllers\Settings\OrganizationController;
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
    Route::delete('settings/organization', [OrganizationController::class, 'destroy'])->name('organization.destroy');
    Route::post('settings/organization/invite', [OrganizationController::class, 'invite'])->name('organization.invite');

    // Organization Brandbook: the central logo/icon/colours/font every
    // customizable surface inherits. Admin-gated inside the controller.
    Route::get('settings/organization/brand', [OrganizationBrandController::class, 'show'])->name('organization.brand.show');
    Route::put('settings/organization/brand', [OrganizationBrandController::class, 'update'])->name('organization.brand.update');
    Route::post('settings/organization/brand/asset', [OrganizationBrandController::class, 'uploadAsset'])->name('organization.brand.asset');
    Route::post('settings/organization/brand/asset/import', [OrganizationBrandController::class, 'importAsset'])->name('organization.brand.asset.import');
    Route::post('settings/organization/brand/from-site', [OrganizationBrandController::class, 'proposeFromSite'])->name('organization.brand.from-site');
    Route::post('settings/organization/brand/palette-proposals', [OrganizationBrandController::class, 'proposePalettes'])->name('organization.brand.palettes');
    Route::post('settings/organization/brand/palette', [OrganizationBrandController::class, 'derivePalette'])->name('organization.brand.palette.derive');

    // Organization Contextbook: the business knowledge every AI interaction in
    // the organization is grounded in. Admin-gated inside the controller.
    Route::get('settings/organization/context', [OrganizationContextController::class, 'show'])->name('organization.context.show');
    Route::put('settings/organization/context', [OrganizationContextController::class, 'update'])->name('organization.context.update');
    Route::post('settings/organization/context/preview', [OrganizationContextController::class, 'preview'])->name('organization.context.preview');
    Route::post('settings/organization/context/draft', [OrganizationContextController::class, 'propose'])->name('organization.context.draft');

    Route::get('settings/sso', [SsoConnectionController::class, 'show'])->name('sso.show');
    Route::put('settings/sso', [SsoConnectionController::class, 'update'])->name('sso.update');
    Route::post('settings/sso/discover', [SsoConnectionController::class, 'discover'])->name('sso.discover');
});
