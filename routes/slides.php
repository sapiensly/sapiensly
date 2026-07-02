<?php

use App\Http\Controllers\SlidesController;
use Illuminate\Support\Facades\Route;

// Signed (no session): the print page headless Chrome captures for the PDF
// export, and the share link for viewers outside the tenant. The signature
// carries the tenant scope.
Route::get('p/{document}/print', [SlidesController::class, 'print'])
    ->name('slides.print')
    ->middleware('signed');
Route::get('share/p/{document}', [SlidesController::class, 'shared'])
    ->name('slides.shared')
    ->middleware('signed');

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::get('slides', [SlidesController::class, 'index'])
        ->name('slides.index');
    Route::get('slides/{document}/builder', [SlidesController::class, 'builder'])
        ->name('slides.builder');
    Route::patch('slides/{document}', [SlidesController::class, 'update'])
        ->name('slides.update');
    Route::post('slides/{document}/builder/messages', [SlidesController::class, 'builderMessage'])
        ->name('slides.builder.message');
    Route::get('slides/{document}/versions', [SlidesController::class, 'versions'])
        ->name('slides.versions');
    Route::post('slides/{document}/versions/{version}/restore', [SlidesController::class, 'restoreVersion'])
        ->name('slides.versions.restore');
    Route::post('slides/{document}/refresh', [SlidesController::class, 'refreshNow'])
        ->name('slides.refresh');
    Route::get('p/{document}', [SlidesController::class, 'present'])
        ->name('slides.present');
    Route::get('slides/{document}/export', [SlidesController::class, 'export'])
        ->name('slides.export');
    Route::post('slides/{document}/share', [SlidesController::class, 'share'])
        ->name('slides.share');
    Route::delete('slides/{document}', [SlidesController::class, 'destroy'])
        ->name('slides.destroy');
});
