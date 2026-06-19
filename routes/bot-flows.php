<?php

use App\Http\Controllers\BotFlowCapabilitiesController;
use App\Http\Controllers\BotFlowController;
use App\Http\Controllers\BotFlowTestController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    // A Bot Flow is edited via its owning surface (chatbots/{chatbot}/flow,
    // whatsapp/{connection}/flow). These endpoints back the shared editor.
    Route::put('flows/{flow}', [BotFlowController::class, 'globalUpdate'])->name('flows.update');
    Route::post('flows/agents', [BotFlowController::class, 'createAgentForLayer'])->name('flows.agents.store');
    Route::get('flows/capabilities/tools', [BotFlowCapabilitiesController::class, 'tools'])->name('flows.capabilities.tools');
    Route::get('flows/capabilities/documents', [BotFlowCapabilitiesController::class, 'documents'])->name('flows.capabilities.documents');
    Route::get('flows/capabilities/knowledge-bases', [BotFlowCapabilitiesController::class, 'knowledgeBases'])->name('flows.capabilities.knowledge-bases');
    Route::get('flows/capabilities/ai-providers', [BotFlowCapabilitiesController::class, 'aiProviders'])->name('flows.capabilities.ai-providers');
    Route::post('flows/{flow}/test/start', [BotFlowTestController::class, 'start'])->name('flows.test.start');
    Route::post('flows/{flow}/test/send', [BotFlowTestController::class, 'send'])->name('flows.test.send');
});
