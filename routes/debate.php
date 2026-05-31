<?php

use App\Http\Controllers\DebateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('debates', [DebateController::class, 'index'])->name('debates.index');
    Route::post('debates', [DebateController::class, 'store'])->name('debates.store');
    Route::get('debates/{debate}', [DebateController::class, 'show'])->name('debates.show');
    Route::patch('debates/{debate}', [DebateController::class, 'rename'])->name('debates.rename');
    Route::delete('debates/{debate}', [DebateController::class, 'destroy'])->name('debates.destroy');
    Route::post('debates/{debate}/stop', [DebateController::class, 'stop'])->name('debates.stop');
});
