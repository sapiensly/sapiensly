<?php

use App\Http\Controllers\ChatActionController;
use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\ChatProjectController;
use App\Http\Controllers\ChatQuestionController;
use App\Http\Controllers\ChatSynthesisController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('chat', [ChatController::class, 'store'])->name('chat.store');
    Route::get('chat/{chat}', [ChatController::class, 'show'])->name('chat.show');
    Route::patch('chat/{chat}', [ChatController::class, 'rename'])->name('chat.rename');
    Route::delete('chat/{chat}', [ChatController::class, 'destroy'])->name('chat.destroy');

    Route::post('chat/{chat}/messages', [ChatMessageController::class, 'store'])->name('chat.messages.store');
    Route::post('chat/{chat}/stop', [ChatMessageController::class, 'stop'])->name('chat.stop');

    // Answer a multiple-choice question card (ask_user_question) — records the
    // choice and continues the conversation as a normal user turn.
    Route::post('chat/{chat}/questions/{message}/answer', [ChatQuestionController::class, 'answer'])
        ->middleware('throttle:60,1')
        ->name('chat.questions.answer');

    // Multi-agent (@mention) thread synthesis + action close.
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('chat/{chat}/synthesize', [ChatSynthesisController::class, 'store'])->name('chat.synthesize');
        Route::post('chat/{chat}/actions/{message}/execute', [ChatActionController::class, 'execute'])->name('chat.actions.execute');
        Route::delete('chat/{chat}/actions/{message}', [ChatActionController::class, 'dismiss'])->name('chat.actions.dismiss');
    });

    Route::post('chat/{chat}/attachments', [ChatAttachmentController::class, 'upload'])->name('chat.attachments.upload');
    Route::get('chat/{chat}/attachments/{attachment}', [ChatAttachmentController::class, 'show'])->name('chat.attachments.show');

    Route::post('chat-projects', [ChatProjectController::class, 'store'])->name('chat-projects.store');
    Route::patch('chat-projects/{chat_project}', [ChatProjectController::class, 'update'])->name('chat-projects.update');
    Route::delete('chat-projects/{chat_project}', [ChatProjectController::class, 'destroy'])->name('chat-projects.destroy');
});
