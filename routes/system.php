<?php

use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\CloudProviderController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\IntegrationEnvironmentController;
use App\Http\Controllers\IntegrationExecutionController;
use App\Http\Controllers\IntegrationRequestController;
use App\Http\Controllers\IntegrationVariableController;
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

    // =========================================================================
    // Integrations
    // =========================================================================
    Route::get('integrations', [IntegrationController::class, 'index'])->name('system.integrations.index');
    Route::get('integrations/templates', [IntegrationController::class, 'templates'])->name('system.integrations.templates');
    Route::get('integrations/create', [IntegrationController::class, 'create'])->name('system.integrations.create');
    Route::post('integrations', [IntegrationController::class, 'store'])->name('system.integrations.store');
    Route::post('integrations/test-connection', [IntegrationController::class, 'testConnectionForPayload'])
        ->name('system.integrations.test-connection-payload');
    Route::get('integrations/{integration}', [IntegrationController::class, 'show'])->name('system.integrations.show');
    Route::get('integrations/{integration}/edit', [IntegrationController::class, 'edit'])->name('system.integrations.edit');
    Route::put('integrations/{integration}', [IntegrationController::class, 'update'])->name('system.integrations.update');
    Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy'])->name('system.integrations.destroy');
    Route::post('integrations/{integration}/duplicate', [IntegrationController::class, 'duplicate'])->name('system.integrations.duplicate');
    Route::post('integrations/{integration}/test-connection', [IntegrationController::class, 'testConnection'])
        ->name('system.integrations.test-connection');

    // Environments
    Route::post('integrations/{integration}/environments', [IntegrationEnvironmentController::class, 'store'])
        ->name('system.integrations.environments.store');
    Route::put('integrations/environments/{environment}', [IntegrationEnvironmentController::class, 'update'])
        ->name('system.integrations.environments.update');
    Route::delete('integrations/environments/{environment}', [IntegrationEnvironmentController::class, 'destroy'])
        ->name('system.integrations.environments.destroy');
    Route::post('integrations/environments/{environment}/activate', [IntegrationEnvironmentController::class, 'activate'])
        ->name('system.integrations.environments.activate');

    // Variables (nested under environment)
    Route::post('integrations/environments/{environment}/variables', [IntegrationVariableController::class, 'store'])
        ->name('system.integrations.variables.store');
    Route::put('integrations/variables/{variable}', [IntegrationVariableController::class, 'update'])
        ->name('system.integrations.variables.update');
    Route::delete('integrations/variables/{variable}', [IntegrationVariableController::class, 'destroy'])
        ->name('system.integrations.variables.destroy');

    // Requests
    Route::post('integrations/{integration}/requests', [IntegrationRequestController::class, 'store'])
        ->name('system.integrations.requests.store');
    Route::get('integrations/requests/{request}', [IntegrationRequestController::class, 'show'])
        ->name('system.integrations.requests.show');
    Route::put('integrations/requests/{request}', [IntegrationRequestController::class, 'update'])
        ->name('system.integrations.requests.update');
    Route::delete('integrations/requests/{request}', [IntegrationRequestController::class, 'destroy'])
        ->name('system.integrations.requests.destroy');
    Route::post('integrations/requests/{request}/duplicate', [IntegrationRequestController::class, 'duplicate'])
        ->name('system.integrations.requests.duplicate');
    Route::post('integrations/requests/{request}/execute', [IntegrationRequestController::class, 'execute'])
        ->middleware('throttle:integration-execute')
        ->name('system.integrations.requests.execute');
    Route::post('integrations/{integration}/execute-ad-hoc', [IntegrationRequestController::class, 'executeAdHoc'])
        ->middleware('throttle:integration-execute')
        ->name('system.integrations.execute-ad-hoc');

    // Executions (read-only)
    Route::get('integrations/{integration}/executions', [IntegrationExecutionController::class, 'index'])
        ->name('system.integrations.executions.index');
    Route::get('integrations/executions/{execution}', [IntegrationExecutionController::class, 'show'])
        ->name('system.integrations.executions.show');
});
