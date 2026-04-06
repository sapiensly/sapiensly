<?php

namespace App\Providers;

use App\Models\OrganizationMembership;
use App\Observers\OrganizationMembershipObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

        OrganizationMembership::observe(OrganizationMembershipObserver::class);

        // SysAdmin bypasses all authorization gates and policies
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('sysadmin')) {
                return true;
            }
        });
    }
}
