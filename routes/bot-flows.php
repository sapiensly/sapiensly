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
    Route::put('bot-flows/{flow}', [BotFlowController::class, 'globalUpdate'])->name('bot-flows.update');
    Route::post('bot-flows/agents', [BotFlowController::class, 'createAgentForLayer'])->name('bot-flows.agents.store');
    Route::get('bot-flows/capabilities/tools', [BotFlowCapabilitiesController::class, 'tools'])->name('bot-flows.capabilities.tools');
    Route::get('bot-flows/capabilities/documents', [BotFlowCapabilitiesController::class, 'documents'])->name('bot-flows.capabilities.documents');
    Route::get('bot-flows/capabilities/knowledge-bases', [BotFlowCapabilitiesController::class, 'knowledgeBases'])->name('bot-flows.capabilities.knowledge-bases');
    Route::get('bot-flows/capabilities/ai-providers', [BotFlowCapabilitiesController::class, 'aiProviders'])->name('bot-flows.capabilities.ai-providers');
    Route::post('bot-flows/{flow}/test/start', [BotFlowTestController::class, 'start'])->name('bot-flows.test.start');
    Route::post('bot-flows/{flow}/test/send', [BotFlowTestController::class, 'send'])->name('bot-flows.test.send');
});
