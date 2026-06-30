<?php

use App\Http\Controllers\Webhooks\FlowWebhookController;
use App\Http\Controllers\Webhooks\IntegrationEventWebhookController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inbound webhook routes
|--------------------------------------------------------------------------
|
| Public (no auth), bound to channel-specific provider validators via
| middleware. Kept isolated from the rest of the app so we can scope CORS,
| rate limiting, and signature verification precisely per provider.
| SubstituteBindings is needed because the `web` group is not applied.
*/

Route::prefix('webhooks/whatsapp')->middleware(SubstituteBindings::class)->group(function () {
    Route::get('{connection}', [WhatsAppWebhookController::class, 'verify'])
        ->name('webhooks.whatsapp.verify');

    Route::post('{connection}', [WhatsAppWebhookController::class, 'receive'])
        ->middleware(['verify.whatsapp.signature', 'throttle:whatsapp-webhook'])
        ->name('webhooks.whatsapp.receive');
});

Route::prefix('webhooks/flows')->middleware(SubstituteBindings::class)->group(function () {
    Route::post('{app}/{workflow}', [FlowWebhookController::class, 'receive'])
        ->where('workflow', 'wkf_[a-z0-9_]+')
        ->middleware('throttle:flows-webhook')
        ->name('webhooks.flows.receive');
});

Route::prefix('webhooks/integrations')->middleware(SubstituteBindings::class)->group(function () {
    Route::post('{integration}', [IntegrationEventWebhookController::class, 'receive'])
        ->where('integration', 'integ_[a-z0-9]+')
        ->middleware('throttle:integration-event-webhook')
        ->name('webhooks.integrations.receive');
});
