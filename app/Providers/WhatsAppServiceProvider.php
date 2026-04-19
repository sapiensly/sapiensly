<?php

namespace App\Providers;

use App\Services\WhatsApp\MetaCloudProvider;
use App\Services\WhatsApp\WhatsAppProviderContract;
use Illuminate\Support\ServiceProvider;

class WhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Bind the contract to the Meta Cloud API implementation. Swapping to
     * Twilio (or any future provider) is a single-line change here.
     */
    public function register(): void
    {
        $this->app->bind(WhatsAppProviderContract::class, MetaCloudProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
