<?php

use App\Http\Controllers\WhatsAppAnalyticsController;
use App\Http\Controllers\WhatsAppConnectionController;
use App\Http\Controllers\WhatsAppConversationController;
use App\Http\Controllers\WhatsAppTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('system/whatsapp')->name('whatsapp.')->group(function () {
    // Connections
    Route::get('/', [WhatsAppConnectionController::class, 'index'])->name('connections.index');
    Route::get('/create', [WhatsAppConnectionController::class, 'create'])->name('connections.create');
    Route::post('/', [WhatsAppConnectionController::class, 'store'])->name('connections.store');
    Route::get('/{whatsapp_connection}', [WhatsAppConnectionController::class, 'show'])->name('connections.show');
    Route::get('/{whatsapp_connection}/edit', [WhatsAppConnectionController::class, 'edit'])->name('connections.edit');
    Route::put('/{whatsapp_connection}', [WhatsAppConnectionController::class, 'update'])->name('connections.update');
    Route::delete('/{whatsapp_connection}', [WhatsAppConnectionController::class, 'destroy'])->name('connections.destroy');

    // Templates (nested under connection)
    Route::get('/{whatsapp_connection}/templates', [WhatsAppTemplateController::class, 'index'])->name('templates.index');
    Route::post('/{whatsapp_connection}/templates', [WhatsAppTemplateController::class, 'store'])->name('templates.store');
    Route::put('/{whatsapp_connection}/templates/{template}', [WhatsAppTemplateController::class, 'update'])->name('templates.update');
    Route::delete('/{whatsapp_connection}/templates/{template}', [WhatsAppTemplateController::class, 'destroy'])->name('templates.destroy');

    // Analytics
    Route::get('/{whatsapp_connection}/analytics', [WhatsAppAnalyticsController::class, 'show'])->name('analytics.show');

    // Conversations (inbox)
    Route::get('/inbox/index', [WhatsAppConversationController::class, 'index'])->name('conversations.index');
    Route::get('/inbox/{conversation}', [WhatsAppConversationController::class, 'show'])->name('conversations.show');
    Route::post('/inbox/{conversation}/takeover', [WhatsAppConversationController::class, 'takeover'])->name('conversations.takeover');
    Route::post('/inbox/{conversation}/release', [WhatsAppConversationController::class, 'release'])->name('conversations.release');
    Route::post('/inbox/{conversation}/reply', [WhatsAppConversationController::class, 'reply'])->name('conversations.reply');
});
