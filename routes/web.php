<?php

use App\Http\Controllers\AccountSwitchController;
use App\Http\Controllers\WidgetAssetController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', fn () => Inertia::render('Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
]));

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
});

require __DIR__.'/settings.php';
require __DIR__.'/agents.php';
require __DIR__.'/standalone-agents.php';
require __DIR__.'/knowledge-bases.php';
require __DIR__.'/tools.php';
require __DIR__.'/documents.php';
require __DIR__.'/chatbots.php';
require __DIR__.'/system.php';
require __DIR__.'/flows.php';
require __DIR__.'/admin.php';
require __DIR__.'/auth.php';
