<?php

use App\Http\Controllers\SlidesController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::get('slides', [SlidesController::class, 'index'])
        ->name('slides.index');
    Route::get('p/{document}', [SlidesController::class, 'present'])
        ->name('slides.present');
    Route::delete('slides/{document}', [SlidesController::class, 'destroy'])
        ->name('slides.destroy');
});
