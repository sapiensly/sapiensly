<?php

use App\Http\Controllers\BotFlowController;
use App\Http\Controllers\ChatbotAnalyticsController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ChatbotPreviewController;
use App\Http\Controllers\WidgetConversationController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::resource('chatbots', ChatbotController::class);

    // The AI Bot's conversational design lives in its Bot Flow.
    Route::get('chatbots/{chatbot}/flow/edit', [BotFlowController::class, 'editForChatbot'])
        ->name('chatbots.flow.edit');
    Route::post('chatbots/{chatbot}/flow/scaffold', [BotFlowController::class, 'scaffold'])
        ->name('chatbots.flow.scaffold');
    Route::post('chatbots/{chatbot}/flow/assistant', [BotFlowController::class, 'converse'])
        ->name('chatbots.flow.assistant');

    Route::get('chatbots/{chatbot}/embed', [ChatbotController::class, 'embed'])
        ->name('chatbots.embed');

    Route::get('chatbots/{chatbot}/preview', [ChatbotController::class, 'preview'])
        ->name('chatbots.preview');

    // Preview chat endpoints
    Route::post('chatbots/{chatbot}/preview/init', [ChatbotPreviewController::class, 'init'])
        ->name('chatbots.preview.init');
    Route::post('chatbots/{chatbot}/preview/send', [ChatbotPreviewController::class, 'send'])
        ->name('chatbots.preview.send');
    Route::get('chatbots/{chatbot}/preview/stream/{conversation}', [ChatbotPreviewController::class, 'stream'])
        ->name('chatbots.preview.stream');
    Route::post('chatbots/{chatbot}/preview/clear', [ChatbotPreviewController::class, 'clear'])
        ->name('chatbots.preview.clear');

    Route::get('chatbots/{chatbot}/analytics', [ChatbotAnalyticsController::class, 'show'])
        ->name('chatbots.analytics');

    Route::get('chatbots/{chatbot}/analytics/data', [ChatbotAnalyticsController::class, 'data'])
        ->name('chatbots.analytics.data');

    Route::get('chatbots/{chatbot}/conversations', [ChatbotController::class, 'conversations'])
        ->name('chatbots.conversations');

    Route::get('chatbots/{chatbot}/conversations/{conversation}', [ChatbotController::class, 'conversation'])
        ->name('chatbots.conversation');

    // Live handoff: the same three verbs as the WhatsApp inbox, on the widget.
    Route::post('chatbots/{chatbot}/conversations/{conversation}/takeover', [WidgetConversationController::class, 'takeover'])
        ->name('chatbots.conversation.takeover');
    Route::post('chatbots/{chatbot}/conversations/{conversation}/release', [WidgetConversationController::class, 'release'])
        ->name('chatbots.conversation.release');
    Route::post('chatbots/{chatbot}/conversations/{conversation}/reply', [WidgetConversationController::class, 'reply'])
        ->name('chatbots.conversation.reply');

    // Presence. The bot may only offer a person while one of these is arriving.
    Route::post('chatbots/operators/heartbeat', [WidgetConversationController::class, 'heartbeat'])
        ->name('chatbots.operators.heartbeat');
    Route::post('chatbots/operators/leave', [WidgetConversationController::class, 'leave'])
        ->name('chatbots.operators.leave');
});
