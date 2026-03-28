<?php

use App\Http\Controllers\ChatbotAnalyticsController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ChatbotPreviewController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::resource('chatbots', ChatbotController::class);

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
});
