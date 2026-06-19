<?php

use App\Http\Controllers\BotFlowCapabilitiesController;
use App\Http\Controllers\BotFlowController;
use App\Http\Controllers\BotFlowTestController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::get('flows', [BotFlowController::class, 'globalIndex'])->name('flows.index');
    Route::get('flows/create', [BotFlowController::class, 'globalCreate'])->name('flows.create');
    Route::post('flows', [BotFlowController::class, 'globalStore'])->name('flows.store');
    Route::get('flows/{flow}/edit', [BotFlowController::class, 'globalEdit'])->name('flows.edit');
    Route::put('flows/{flow}', [BotFlowController::class, 'globalUpdate'])->name('flows.update');
    Route::delete('flows/{flow}', [BotFlowController::class, 'globalDestroy'])->name('flows.destroy');
    Route::post('flows/agents', [BotFlowController::class, 'createAgentForLayer'])->name('flows.agents.store');
    Route::get('flows/capabilities/tools', [BotFlowCapabilitiesController::class, 'tools'])->name('flows.capabilities.tools');
    Route::get('flows/capabilities/documents', [BotFlowCapabilitiesController::class, 'documents'])->name('flows.capabilities.documents');
    Route::get('flows/capabilities/knowledge-bases', [BotFlowCapabilitiesController::class, 'knowledgeBases'])->name('flows.capabilities.knowledge-bases');
    Route::get('flows/capabilities/ai-providers', [BotFlowCapabilitiesController::class, 'aiProviders'])->name('flows.capabilities.ai-providers');
    Route::post('flows/{flow}/test/start', [BotFlowTestController::class, 'start'])->name('flows.test.start');
    Route::post('flows/{flow}/test/send', [BotFlowTestController::class, 'send'])->name('flows.test.send');

    Route::get('agents/{agent}/flows', [BotFlowController::class, 'index'])->name('agents.flows.index');
    Route::get('agents/{agent}/flows/create', [BotFlowController::class, 'create'])->name('agents.flows.create');
    Route::post('agents/{agent}/flows', [BotFlowController::class, 'store'])->name('agents.flows.store');
    Route::get('agents/{agent}/flows/{flow}/edit', [BotFlowController::class, 'edit'])->name('agents.flows.edit');
    Route::put('agents/{agent}/flows/{flow}', [BotFlowController::class, 'update'])->name('agents.flows.update');
    Route::delete('agents/{agent}/flows/{flow}', [BotFlowController::class, 'destroy'])->name('agents.flows.destroy');
    Route::post('agents/{agent}/flows/{flow}/activate', [BotFlowController::class, 'activate'])->name('agents.flows.activate');
});
