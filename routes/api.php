<?php

use App\Http\Controllers\Api\Widget\ChatController;
use App\Http\Controllers\Api\Widget\ConfigController;
use App\Http\Controllers\Api\Widget\ErrorController;
use App\Http\Controllers\Api\Widget\FeedbackController;
use App\Http\Controllers\Api\Widget\SessionController;
use App\Http\Middleware\ThrottleWidgetRequests;
use App\Http\Middleware\ValidateWidgetApiToken;
use App\Http\Middleware\ValidateWidgetOrigin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Widget API Routes
|--------------------------------------------------------------------------
|
| These routes are used by the embeddable JavaScript widget to communicate
| with the Sapiensly backend. They handle session management, chat messages,
| and feedback collection.
|
*/

Route::prefix('widget/v1')->group(function () {
    // Public config endpoint - token is in the URL
    Route::get('/config/{token}', [ConfigController::class, 'show'])
        ->middleware([ValidateWidgetOrigin::class, ThrottleWidgetRequests::class])
        ->name('widget.config');

    // Public error reporting endpoint - no auth required, rate limited
    Route::post('/errors', [ErrorController::class, 'store'])
        ->middleware([ThrottleWidgetRequests::class])
        ->name('widget.errors');

    // Authenticated endpoints - require Bearer token
    Route::middleware([
        ValidateWidgetApiToken::class,
        ValidateWidgetOrigin::class,
        ThrottleWidgetRequests::class,
    ])->group(function () {
        // Session management
        Route::post('/sessions', [SessionController::class, 'store'])
            ->name('widget.sessions.store');
        Route::patch('/sessions/{session}', [SessionController::class, 'update'])
            ->name('widget.sessions.update');

        // Conversations
        Route::post('/conversations', [ChatController::class, 'store'])
            ->name('widget.conversations.store');
        Route::get('/conversations/{conversation}/messages', [ChatController::class, 'messages'])
            ->name('widget.conversations.messages');
        Route::post('/conversations/{conversation}/messages', [ChatController::class, 'sendMessage'])
            ->name('widget.conversations.send');
        Route::get('/conversations/{conversation}/stream', [ChatController::class, 'stream'])
            ->name('widget.conversations.stream');

        // Feedback
        Route::post('/conversations/{conversation}/feedback', [FeedbackController::class, 'store'])
            ->name('widget.conversations.feedback');
    });
});
