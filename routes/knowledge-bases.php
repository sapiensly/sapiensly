<?php

use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\KnowledgeBaseDocumentController;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function () {
    Route::resource('knowledge-bases', KnowledgeBaseController::class);

    // Legacy document routes (KnowledgeBaseDocument model)
    Route::post('knowledge-bases/{knowledge_base}/documents', [KnowledgeBaseDocumentController::class, 'store'])
        ->name('knowledge-bases.documents.store');
    Route::post('knowledge-bases/{knowledge_base}/documents/url', [KnowledgeBaseDocumentController::class, 'storeUrl'])
        ->name('knowledge-bases.documents.store-url');
    Route::delete('knowledge-bases/{knowledge_base}/documents/{document}', [KnowledgeBaseDocumentController::class, 'destroy'])
        ->name('knowledge-bases.documents.destroy');
    Route::post('knowledge-bases/{knowledge_base}/documents/{document}/reprocess', [KnowledgeBaseDocumentController::class, 'reprocess'])
        ->name('knowledge-bases.documents.reprocess');

    // New document attachment routes (Document model)
    Route::post('knowledge-bases/{knowledge_base}/attach-documents', [KnowledgeBaseController::class, 'attachDocuments'])
        ->name('knowledge-bases.attach-documents');
    Route::post('knowledge-bases/{knowledge_base}/reprocess-document/{document}', [KnowledgeBaseController::class, 'reprocessDocument'])
        ->name('knowledge-bases.reprocess-document');
    Route::delete('knowledge-bases/{knowledge_base}/detach-document/{document}', [KnowledgeBaseController::class, 'detachDocument'])
        ->name('knowledge-bases.detach-document');
});
