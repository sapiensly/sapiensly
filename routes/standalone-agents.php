<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ChatStreamController;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function () {
    Route::resource('agents', AgentController::class);
    Route::post('agents/{agent}/duplicate', [AgentController::class, 'duplicate'])->name('agents.duplicate');

    // Chat routes
    Route::get('agents/{agent}/chat', [AgentController::class, 'chat'])->name('agents.chat');
    Route::post('agents/{agent}/chat', [AgentController::class, 'sendMessage'])->name('agents.chat.send');
    Route::get('agents/{agent}/chat/{conversation}/stream', [ChatStreamController::class, 'stream'])
        ->name('agents.chat.stream');
});
