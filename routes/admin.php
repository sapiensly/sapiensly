<?php

use App\Http\Controllers\Admin\AccessSettingsController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminUserController;
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
