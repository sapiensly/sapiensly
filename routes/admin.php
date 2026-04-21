<?php

use App\Http\Controllers\Admin\AdminAccessController;
use App\Http\Controllers\Admin\AdminAiController;
use App\Http\Controllers\Admin\AdminCloudController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminStackController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\ImpersonateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:sysadmin'])->prefix('admin')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('admin.dashboard');

    Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::post('/users/invite', [AdminUserController::class, 'invite'])->name('admin.users.invite');
    Route::post('/users/{user}/block', [AdminUserController::class, 'block'])->name('admin.users.block');
    Route::post('/users/{user}/unblock', [AdminUserController::class, 'unblock'])->name('admin.users.unblock');
    Route::post('/users/{user}/resend-verification', [AdminUserController::class, 'resendVerification'])->name('admin.users.resend-verification');
    Route::post('/users/{user}/reset-2fa', [AdminUserController::class, 'resetTwoFactor'])->name('admin.users.reset-2fa');
    Route::post('/users/{user}/impersonate', [ImpersonateController::class, 'start'])->name('admin.impersonate.start');
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');

    Route::get('/access', [AdminAccessController::class, 'index'])->name('admin.access.index');
    Route::patch('/access', [AdminAccessController::class, 'update'])->name('admin.access.update');
    Route::get('/access/users-without-2fa', [AdminAccessController::class, 'usersWithoutTwoFactor'])->name('admin.access.users-without-2fa');

    Route::get('/ai', [AdminAiController::class, 'defaults'])->name('admin.ai.defaults');
    Route::get('/ai/catalog', [AdminAiController::class, 'catalog'])->name('admin.ai.catalog');
    Route::get('/ai/usage', [AdminAiController::class, 'usage'])->name('admin.ai.usage');
    Route::patch('/ai/defaults', [AdminAiController::class, 'updateDefaults'])->name('admin.ai.defaults.update');
    Route::patch('/ai/catalog/{model}', [AdminAiController::class, 'toggleModel'])->name('admin.ai.catalog.toggle');
    Route::post('/ai/providers/{provider}/rotate-key', [AdminAiController::class, 'rotateKey'])->name('admin.ai.rotate-key');
    Route::post('/ai/test-connection', [AdminAiController::class, 'testConnection'])->name('admin.ai.test-connection');

    Route::get('/cloud', [AdminCloudController::class, 'index'])->name('admin.cloud.index');

    Route::get('/stack', [AdminStackController::class, 'index'])->name('admin.stack.index');
});

// Stop impersonation — only requires session key, no role check
Route::middleware(['auth'])->post('/admin/impersonate/stop', [ImpersonateController::class, 'stop'])
    ->name('admin.impersonate.stop');
