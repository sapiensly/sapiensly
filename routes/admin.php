<?php

use App\Http\Controllers\Admin\AccessSettingsController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\ImpersonateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:sysadmin'])->prefix('admin')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('admin.dashboard');
    Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users');
    Route::post('/users/{user}/impersonate', [ImpersonateController::class, 'start'])->name('admin.impersonate.start');
    Route::get('/access-settings', [AccessSettingsController::class, 'index'])->name('admin.access-settings');
    Route::put('/access-settings', [AccessSettingsController::class, 'update'])->name('admin.access-settings.update');
});

// Stop impersonation — only requires session key, no role check
Route::middleware(['auth'])->post('/admin/impersonate/stop', [ImpersonateController::class, 'stop'])
    ->name('admin.impersonate.stop');
