<?php

use App\Http\Controllers\Admin\AccessSettingsController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminV2AccessController;
use App\Http\Controllers\Admin\AdminV2AiController;
use App\Http\Controllers\Admin\AdminV2CloudController;
use App\Http\Controllers\Admin\AdminV2DashboardController;
use App\Http\Controllers\Admin\AdminV2StackController;
use App\Http\Controllers\Admin\AdminV2UserController;
use App\Http\Controllers\Admin\GlobalAiController;
use App\Http\Controllers\Admin\GlobalCloudController;
use App\Http\Controllers\Admin\ImpersonateController;
use App\Http\Controllers\StackController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:sysadmin'])->prefix('admin')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('admin.dashboard');
    Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users');
    Route::get('/users/create', [AdminUserController::class, 'create'])->name('admin.users.create');
    Route::post('/users', [AdminUserController::class, 'store'])->name('admin.users.store');
    Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('admin.users.edit');
    Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
    Route::post('/users/{user}/block', [AdminUserController::class, 'block'])->name('admin.users.block');
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
    Route::post('/users/{user}/impersonate', [ImpersonateController::class, 'start'])->name('admin.impersonate.start');
    Route::get('/access-settings', [AccessSettingsController::class, 'index'])->name('admin.access-settings');
    Route::put('/access-settings', [AccessSettingsController::class, 'update'])->name('admin.access-settings.update');
    Route::get('/system/stack', [StackController::class, 'index'])->name('admin.system.stack');
    Route::get('/system/global-ai', [GlobalAiController::class, 'index'])->name('admin.system.global-ai');
    Route::post('/system/global-ai', [GlobalAiController::class, 'store'])->name('admin.system.global-ai.store');
    Route::post('/system/global-ai/test-connection', [GlobalAiController::class, 'testConnection'])->name('admin.system.global-ai.test-connection');
    Route::post('/system/global-ai/catalog', [GlobalAiController::class, 'catalogStore'])->name('admin.system.global-ai.catalog.store');
    Route::patch('/system/global-ai/catalog/{catalogModel}', [GlobalAiController::class, 'catalogUpdate'])->name('admin.system.global-ai.catalog.update');
    Route::delete('/system/global-ai/catalog/{catalogModel}', [GlobalAiController::class, 'catalogDestroy'])->name('admin.system.global-ai.catalog.destroy');
    Route::get('/system/global-cloud', [GlobalCloudController::class, 'index'])->name('admin.system.global-cloud');
    Route::post('/system/global-cloud/storage', [GlobalCloudController::class, 'storeStorage'])->name('admin.system.global-cloud.storage.store');
    Route::post('/system/global-cloud/database', [GlobalCloudController::class, 'storeDatabase'])->name('admin.system.global-cloud.database.store');
    Route::post('/system/global-cloud/storage/test-connection', [GlobalCloudController::class, 'testStorage'])->name('admin.system.global-cloud.storage.test-connection');
    Route::post('/system/global-cloud/database/test-connection', [GlobalCloudController::class, 'testDatabase'])->name('admin.system.global-cloud.database.test-connection');
    Route::post('/system/global-cloud/database/inspect-vector', [GlobalCloudController::class, 'inspectVector'])->name('admin.system.global-cloud.database.inspect-vector');
    Route::post('/system/global-cloud/database/install-vector', [GlobalCloudController::class, 'installVector'])->name('admin.system.global-cloud.database.install-vector');
});

// Stop impersonation — only requires session key, no role check
Route::middleware(['auth'])->post('/admin/impersonate/stop', [ImpersonateController::class, 'stop'])
    ->name('admin.impersonate.stop');

/*
 * Admin V2 — new Sapiensly-branded admin UI, ported screen-by-screen behind
 * /admin2 while the existing /admin routes stay live for the rest of the team.
 * Cutover happens once every screen above is ported and reviewed.
 */
Route::middleware(['auth', 'verified', 'role:sysadmin'])->prefix('admin2')->group(function () {
    Route::get('/', [AdminV2DashboardController::class, 'index'])->name('admin2.dashboard');

    Route::get('/users', [AdminV2UserController::class, 'index'])->name('admin2.users.index');
    Route::post('/users/invite', [AdminV2UserController::class, 'invite'])->name('admin2.users.invite');
    Route::post('/users/{user}/block', [AdminV2UserController::class, 'block'])->name('admin2.users.block');
    Route::post('/users/{user}/unblock', [AdminV2UserController::class, 'unblock'])->name('admin2.users.unblock');
    Route::post('/users/{user}/resend-verification', [AdminV2UserController::class, 'resendVerification'])->name('admin2.users.resend-verification');
    Route::post('/users/{user}/reset-2fa', [AdminV2UserController::class, 'resetTwoFactor'])->name('admin2.users.reset-2fa');
    Route::delete('/users/{user}', [AdminV2UserController::class, 'destroy'])->name('admin2.users.destroy');

    Route::get('/access', [AdminV2AccessController::class, 'index'])->name('admin2.access.index');
    Route::patch('/access', [AdminV2AccessController::class, 'update'])->name('admin2.access.update');
    Route::get('/access/users-without-2fa', [AdminV2AccessController::class, 'usersWithoutTwoFactor'])->name('admin2.access.users-without-2fa');

    Route::get('/ai', [AdminV2AiController::class, 'defaults'])->name('admin2.ai.defaults');
    Route::get('/ai/catalog', [AdminV2AiController::class, 'catalog'])->name('admin2.ai.catalog');
    Route::get('/ai/usage', [AdminV2AiController::class, 'usage'])->name('admin2.ai.usage');
    Route::patch('/ai/defaults', [AdminV2AiController::class, 'updateDefaults'])->name('admin2.ai.defaults.update');
    Route::patch('/ai/catalog/{model}', [AdminV2AiController::class, 'toggleModel'])->name('admin2.ai.catalog.toggle');
    Route::post('/ai/providers/{provider}/rotate-key', [AdminV2AiController::class, 'rotateKey'])->name('admin2.ai.rotate-key');
    Route::post('/ai/test-connection', [AdminV2AiController::class, 'testConnection'])->name('admin2.ai.test-connection');

    Route::get('/cloud', [AdminV2CloudController::class, 'index'])->name('admin2.cloud.index');

    Route::get('/stack', [AdminV2StackController::class, 'index'])->name('admin2.stack.index');
});
