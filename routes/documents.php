<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FolderController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    // Documents
    Route::resource('documents', DocumentController::class)->except(['create', 'edit']);
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');
    Route::post('documents/{document}/move', [DocumentController::class, 'move'])
        ->name('documents.move');

    // Folders
    Route::apiResource('folders', FolderController::class)->except(['show']);
});
