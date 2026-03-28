<?php

use App\Http\Controllers\AgentTeamController;
use App\Http\Controllers\TeamStreamController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::resource('agent-teams', AgentTeamController::class);

    // Team chat routes
    Route::get('agent-teams/{agentTeam}/chat', [AgentTeamController::class, 'chat'])
        ->name('agent-teams.chat');
    Route::post('agent-teams/{agentTeam}/messages', [AgentTeamController::class, 'sendMessage'])
        ->name('agent-teams.messages.send');
    Route::post('agent-teams/{agentTeam}/new-conversation', [AgentTeamController::class, 'newConversation'])
        ->name('agent-teams.new-conversation');

    // Team streaming route
    Route::get('agent-teams/{agentTeam}/stream/{conversation}', [TeamStreamController::class, 'stream'])
        ->name('agent-teams.stream');
});
