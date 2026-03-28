<?php

use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\StackController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->prefix('system')->group(function () {
    Route::resource('ai-providers', AiProviderController::class)
        ->except(['show'])
        ->names([
            'index' => 'system.ai-providers.index',
            'create' => 'system.ai-providers.create',
            'store' => 'system.ai-providers.store',
            'edit' => 'system.ai-providers.edit',
            'update' => 'system.ai-providers.update',
            'destroy' => 'system.ai-providers.destroy',
        ]);

    Route::post('ai-providers/{ai_provider}/test-connection', [AiProviderController::class, 'testConnection'])
        ->name('system.ai-providers.test-connection');
    Route::post('ai-providers/{ai_provider}/set-default', [AiProviderController::class, 'setDefault'])
        ->name('system.ai-providers.set-default');
    Route::post('ai-providers/{ai_provider}/set-default-embeddings', [AiProviderController::class, 'setDefaultEmbeddings'])
        ->name('system.ai-providers.set-default-embeddings');

    Route::get('stack', [StackController::class, 'index'])->name('system.stack');
});
