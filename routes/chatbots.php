<?php

use App\Http\Controllers\ChatbotAnalyticsController;
use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function () {
    Route::resource('chatbots', ChatbotController::class);

    Route::get('chatbots/{chatbot}/embed', [ChatbotController::class, 'embed'])
        ->name('chatbots.embed');

    Route::get('chatbots/{chatbot}/preview', [ChatbotController::class, 'preview'])
        ->name('chatbots.preview');

    Route::get('chatbots/{chatbot}/analytics', [ChatbotAnalyticsController::class, 'show'])
        ->name('chatbots.analytics');

    Route::get('chatbots/{chatbot}/analytics/data', [ChatbotAnalyticsController::class, 'data'])
        ->name('chatbots.analytics.data');

    Route::get('chatbots/{chatbot}/conversations', [ChatbotController::class, 'conversations'])
        ->name('chatbots.conversations');

    Route::get('chatbots/{chatbot}/conversations/{conversation}', [ChatbotController::class, 'conversation'])
        ->name('chatbots.conversation');
});
