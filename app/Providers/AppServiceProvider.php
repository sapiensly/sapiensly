<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Pgvector\Laravel\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register pgvector Schema macros only on PostgreSQL
        // We disabled pgvector's auto-discovery to prevent its migration from running on SQLite
        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::register();
        }
    }
}
