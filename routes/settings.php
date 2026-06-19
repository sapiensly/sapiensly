<?php

use App\Http\Controllers\Settings\McpTokenController;
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

    Route::get('settings/sso', [SsoConnectionController::class, 'show'])->name('sso.show');
    Route::put('settings/sso', [SsoConnectionController::class, 'update'])->name('sso.update');
    Route::post('settings/sso/discover', [SsoConnectionController::class, 'discover'])->name('sso.discover');

    Route::get('settings/mcp', [McpTokenController::class, 'show'])->name('mcp-tokens.show');
    Route::post('settings/mcp', [McpTokenController::class, 'store'])->name('mcp-tokens.store');
    Route::delete('settings/mcp/{mcpToken}', [McpTokenController::class, 'destroy'])->name('mcp-tokens.destroy');
});
