<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\PublicDocumentController;
use Illuminate\Support\Facades\Route;

// Public share endpoint — no auth, serves artifacts marked as Public.
Route::get('share/d/{id}', [PublicDocumentController::class, 'show'])
    ->name('documents.public');

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    // Documents
    Route::get('documents/create', [DocumentController::class, 'create'])
        ->name('documents.create');
    Route::post('documents/generate', [DocumentController::class, 'generate'])
        ->name('documents.generate');
    Route::post('documents/refine', [DocumentController::class, 'refine'])
        ->name('documents.refine');
    Route::post('documents/inline', [DocumentController::class, 'storeInline'])
        ->name('documents.store-inline');
    Route::get('documents/{document}/edit', [DocumentController::class, 'edit'])
        ->name('documents.edit');
    Route::patch('documents/{document}/inline', [DocumentController::class, 'updateInline'])
        ->name('documents.update-inline');
    Route::resource('documents', DocumentController::class)->except(['create', 'edit']);
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');
    Route::post('documents/{document}/move', [DocumentController::class, 'move'])
        ->name('documents.move');

    // Folders
    Route::apiResource('folders', FolderController::class)->except(['show']);
});
