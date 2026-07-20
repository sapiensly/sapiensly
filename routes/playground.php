<?php

use App\Http\Controllers\PlaygroundController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::get('playground', [PlaygroundController::class, 'index'])->name('playground.index');
    Route::post('playground/run', [PlaygroundController::class, 'run'])->name('playground.run');
    Route::get('playground/run/{run}', [PlaygroundController::class, 'status'])->name('playground.run.status');
    Route::get('playground/history', [PlaygroundController::class, 'history'])->name('playground.history');
    Route::get('playground/history/{run}', [PlaygroundController::class, 'show'])->name('playground.history.show');
});
