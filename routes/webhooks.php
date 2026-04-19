<?php

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
