<?php

use App\Http\Controllers\FlowCapabilitiesController;
use App\Http\Controllers\FlowController;
use App\Http\Controllers\FlowTestController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::get('flows', [FlowController::class, 'globalIndex'])->name('flows.index');
    Route::get('flows/create', [FlowController::class, 'globalCreate'])->name('flows.create');
    Route::post('flows', [FlowController::class, 'globalStore'])->name('flows.store');
    Route::get('flows/{flow}/edit', [FlowController::class, 'globalEdit'])->name('flows.edit');
    Route::put('flows/{flow}', [FlowController::class, 'globalUpdate'])->name('flows.update');
    Route::delete('flows/{flow}', [FlowController::class, 'globalDestroy'])->name('flows.destroy');
    Route::post('flows/agents', [FlowController::class, 'createAgentForLayer'])->name('flows.agents.store');
    Route::get('flows/capabilities/tools', [FlowCapabilitiesController::class, 'tools'])->name('flows.capabilities.tools');
    Route::get('flows/capabilities/documents', [FlowCapabilitiesController::class, 'documents'])->name('flows.capabilities.documents');
    Route::get('flows/capabilities/knowledge-bases', [FlowCapabilitiesController::class, 'knowledgeBases'])->name('flows.capabilities.knowledge-bases');
    Route::get('flows/capabilities/ai-providers', [FlowCapabilitiesController::class, 'aiProviders'])->name('flows.capabilities.ai-providers');
    Route::post('flows/{flow}/test/start', [FlowTestController::class, 'start'])->name('flows.test.start');
    Route::post('flows/{flow}/test/send', [FlowTestController::class, 'send'])->name('flows.test.send');

    Route::get('agents/{agent}/flows', [FlowController::class, 'index'])->name('agents.flows.index');
    Route::get('agents/{agent}/flows/create', [FlowController::class, 'create'])->name('agents.flows.create');
    Route::post('agents/{agent}/flows', [FlowController::class, 'store'])->name('agents.flows.store');
    Route::get('agents/{agent}/flows/{flow}/edit', [FlowController::class, 'edit'])->name('agents.flows.edit');
    Route::put('agents/{agent}/flows/{flow}', [FlowController::class, 'update'])->name('agents.flows.update');
    Route::delete('agents/{agent}/flows/{flow}', [FlowController::class, 'destroy'])->name('agents.flows.destroy');
    Route::post('agents/{agent}/flows/{flow}/activate', [FlowController::class, 'activate'])->name('agents.flows.activate');
});
