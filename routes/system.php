<?php

use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\CloudProviderController;
use App\Http\Controllers\IntegrationController;
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

    Route::get('cloud-providers', [CloudProviderController::class, 'index'])
        ->name('system.cloud-providers.index');
    Route::post('cloud-providers/storage', [CloudProviderController::class, 'storeStorage'])
        ->name('system.cloud-providers.storage.store');
    Route::post('cloud-providers/database', [CloudProviderController::class, 'storeDatabase'])
        ->name('system.cloud-providers.database.store');
    Route::post('cloud-providers/storage/test-connection', [CloudProviderController::class, 'testStorage'])
        ->name('system.cloud-providers.storage.test-connection');
    Route::post('cloud-providers/database/test-connection', [CloudProviderController::class, 'testDatabase'])
        ->name('system.cloud-providers.database.test-connection');
    Route::delete('cloud-providers/{kind}', [CloudProviderController::class, 'destroy'])
        ->whereIn('kind', ['storage', 'database'])
        ->name('system.cloud-providers.destroy');

    Route::post('cloud-providers/database/inspect-vector', [CloudProviderController::class, 'inspectVector'])
        ->name('system.cloud-providers.database.inspect-vector');
    Route::post('cloud-providers/database/install-vector', [CloudProviderController::class, 'installVector'])
        ->name('system.cloud-providers.database.install-vector');

    Route::get('integrations', [IntegrationController::class, 'index'])
        ->name('system.integrations.index');
});
