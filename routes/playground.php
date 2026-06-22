<?php

use App\Http\Controllers\PlaygroundController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::get('playground', [PlaygroundController::class, 'index'])->name('playground.index');
    Route::post('playground/run', [PlaygroundController::class, 'run'])->name('playground.run');
});
