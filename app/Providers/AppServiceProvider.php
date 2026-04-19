<?php

namespace App\Providers;

use App\Models\OrganizationMembership;
use App\Observers\OrganizationMembershipObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Throttle integration test-runs per user (falls back to IP for guests).
        RateLimiter::for('integration-execute', function (Request $request) {
            $limit = (int) config('integrations.execute_rate_limit.per_minute', 60);

            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        // WhatsApp webhook receiver: per-connection burst control (Meta rotates
        // IPs so IP-based throttling is useless here).
        RateLimiter::for('whatsapp-webhook', function (Request $request) {
            $connectionId = $request->route('connection');
            $key = is_object($connectionId)
                ? (method_exists($connectionId, 'getKey') ? $connectionId->getKey() : 'unknown')
                : (string) $connectionId;

            return Limit::perMinute(600)->by('wa-webhook:'.$key);
        });
    }
}
