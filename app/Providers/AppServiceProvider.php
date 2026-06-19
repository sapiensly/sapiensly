<?php

namespace App\Providers;

use App\Models\OrganizationMembership;
use App\Observers\OrganizationMembershipObserver;
use App\Services\Security\Ssrf\DnsResolver;
use App\Services\Security\Ssrf\IpRangeMatcher;
use App\Services\Security\Ssrf\SsrfGuard;
use App\Services\Security\Ssrf\SystemDnsResolver;
use App\Support\Tenancy\TenantCache;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Pgvector\Laravel\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant scope per request/worker, shared by the HTTP middleware,
        // queue middleware and account switching.
        $this->app->singleton(TenantContext::class);

        // Tenant-scoped cache (the Redis-layer analog to RLS): keys are
        // transparently namespaced by the active tenant scope, so cached
        // tenant data can never leak across organizations/users.
        $this->app->singleton(TenantCache::class, function ($app) {
            return new TenantCache(
                $app->make('cache.store'),
                $app->make(TenantContext::class),
            );
        });

        // SSRF guard: the system resolver is the production DNS backend; tests
        // bind a FakeDnsResolver instead.
        $this->app->bind(
            DnsResolver::class,
            SystemDnsResolver::class,
        );

        // Inject the configured host allowlist so the guard itself stays free
        // of the config() helper (pure + unit-testable).
        $this->app->bind(SsrfGuard::class, function ($app) {
            return new SsrfGuard(
                $app->make(DnsResolver::class),
                $app->make(IpRangeMatcher::class),
                array_values((array) config('security.ssrf.host_allowlist', [])),
            );
        });

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

        $this->propagateTenantContextToQueue();

        $this->forceOwnerConnectionForMigrations();

        // Consent screen shown to a user when an MCP client (e.g. claude.ai)
        // requests OAuth access via the MCP server's Passport flow.
        Passport::authorizationView(fn ($parameters) => view('mcp.authorize', $parameters));

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

        RateLimiter::for('flows-webhook', function (Request $request) {
            $workflow = (string) $request->route('workflow');

            return Limit::perMinute(300)->by('flows-webhook:'.$workflow);
        });

        $this->registerRuntimeRateLimiters();
    }

    /**
     * Force schema DDL (migrations) and seeders onto the database OWNER — the
     * `pgsql` connection — whenever an operator forgets the `--database=pgsql`
     * flag.
     *
     * The app default connection is `platform` (least-privilege), which cannot
     * own or relocate tables. Running `migrate` on it would create tables owned
     * by `platform_app`, so the schema-relocate migration (which runs as the
     * owner) then fails with `must be owner of table ...` on a real cluster —
     * exactly the failure seen on Supabase. An explicit `--database` always
     * wins, so deliberate overrides still work.
     */
    private function forceOwnerConnectionForMigrations(): void
    {
        $ownerCommands = [
            'migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:rollback',
            'migrate:reset', 'migrate:install', 'db:seed', 'db:wipe', 'schema:dump',
        ];

        Event::listen(function (CommandStarting $event) use ($ownerCommands): void {
            if (! in_array($event->command, $ownerCommands, true)) {
                return;
            }

            if ($event->input->hasParameterOption('--database', true)) {
                return;
            }

            if (config('database.default') === 'platform') {
                config(['database.default' => 'pgsql']);
            }
        });
    }

    /**
     * Carry the dispatch-time tenant scope into every queued job's payload and
     * re-establish it in the worker, where no HTTP middleware runs. This gives
     * jobs the same RLS GUCs as the request that dispatched them with no
     * per-job wiring. The `before` hook ALWAYS sets the context (to null when a
     * job carries none) so scope never leaks between jobs on a long-lived
     * worker. Sync dispatch (tests) runs in-process with the context already
     * set, so the absent payload there is harmless.
     */
    private function propagateTenantContextToQueue(): void
    {
        Queue::createPayloadUsing(function (): array {
            $context = $this->app->make(TenantContext::class);

            return [
                'tenant_organization_id' => $context->organizationId(),
                'tenant_user_id' => $context->userId(),
            ];
        });

        Queue::before(function (JobProcessing $event): void {
            $payload = $event->job->payload();

            $organizationId = $payload['tenant_organization_id'] ?? null;
            $userId = $payload['tenant_user_id'] ?? null;

            $this->app->make(TenantContext::class)->set(
                $organizationId,
                $userId === null ? null : (int) $userId,
            );
        });
    }

    /**
     * Per-surface throttles for the authenticated App runtime / builder
     * endpoints. Each applies a per-USER (identity) and a per-ORG (paying
     * tenant) dimension — orgs never share a bucket. The AI surface adds a
     * per-org-per-day cost ceiling. See config/security.php for the values and
     * the fail-open/closed decision.
     */
    private function registerRuntimeRateLimiters(): void
    {
        // Cheap-but-real end-user traffic on deployed Apps.
        RateLimiter::for('runtime-actions', function (Request $request) {
            return $this->tenantLimits($request, 'runtime-actions', [
                ['kind' => 'user', 'max' => (int) config('security.rate_limits.runtime_actions.per_user')],
                ['kind' => 'org', 'max' => (int) config('security.rate_limits.runtime_actions.per_org')],
            ]);
        });

        // Author manually test-running a workflow during a build.
        RateLimiter::for('builder-workflow-run', function (Request $request) {
            return $this->tenantLimits($request, 'builder-workflow-run', [
                ['kind' => 'user', 'max' => (int) config('security.rate_limits.builder_workflow_run.per_user')],
                ['kind' => 'org', 'max' => (int) config('security.rate_limits.builder_workflow_run.per_org')],
            ]);
        });

        // Every hit enqueues a paid Claude job — per-minute caps PLUS a daily
        // org quota that is the actual cost control.
        RateLimiter::for('builder-ai', function (Request $request) {
            return $this->tenantLimits($request, 'builder-ai', [
                ['kind' => 'user', 'max' => (int) config('security.rate_limits.builder_ai.per_user')],
                ['kind' => 'org', 'max' => (int) config('security.rate_limits.builder_ai.per_org')],
                ['kind' => 'daily',
                    'max' => (int) config('security.rate_limits.builder_ai.per_org_daily'),
                    'user_max' => (int) config('security.rate_limits.builder_ai.per_user_daily'),
                ],
            ]);
        });

        // MCP endpoint. Per-user + per-org per-minute caps; the user is set by
        // AuthenticateMcpToken before this runs.
        RateLimiter::for('mcp', function (Request $request) {
            return $this->tenantLimits($request, 'mcp', [
                ['kind' => 'user', 'max' => (int) config('security.rate_limits.mcp.per_user')],
                ['kind' => 'org', 'max' => (int) config('security.rate_limits.mcp.per_org')],
            ]);
        });
    }

    /**
     * Build the Limit set for a surface. The per-user limit ALWAYS applies (so
     * no request rides a shared anonymous bucket). The per-minute org limit
     * applies only when the user has an org. The 'daily' ceiling ALWAYS applies
     * too — keyed by org if present, else by user (`user_max`) — so a no-org
     * account can't bypass the daily cost cap. A null user on an auth-only
     * route is an invalid state and is denied outright.
     *
     * @param  list<array{kind: string, max?: int, user_max?: int}>  $specs
     * @return list<Limit>
     */
    private function tenantLimits(Request $request, string $surface, array $specs): array
    {
        $user = $request->user();
        if ($user === null) {
            // Should never happen behind `auth`; fail closed rather than share a bucket.
            return [Limit::perMinute(0)->by('invalid:no-user')];
        }

        $orgId = $user->organization_id;
        $limits = [];

        foreach ($specs as $spec) {
            switch ($spec['kind']) {
                case 'user':
                    $limits[] = Limit::perMinute($spec['max'])
                        ->by("rl:{$surface}:u:{$user->id}")
                        ->response($this->throttledResponse($surface, 'user', $user->id, $orgId));
                    break;

                case 'org':
                    // Per-minute tenant cap — only meaningful when there is an org.
                    if ($orgId !== null) {
                        $limits[] = Limit::perMinute($spec['max'])
                            ->by("rl:{$surface}:o:{$orgId}")
                            ->response($this->throttledResponse($surface, 'org', $user->id, $orgId));
                    }
                    break;

                case 'daily':
                    // The daily cost ceiling ALWAYS applies: scoped to the org
                    // when there is one, otherwise to the user. A no-org account
                    // must still have a daily cap, not just a per-minute burst
                    // limit (else 20/min sustained = unbounded daily spend).
                    if ($orgId !== null) {
                        $limits[] = Limit::perDay($spec['max'])
                            ->by("rl:{$surface}:od:{$orgId}")
                            ->response($this->throttledResponse($surface, 'org_daily', $user->id, $orgId));
                    } else {
                        $limits[] = Limit::perDay($spec['user_max'])
                            ->by("rl:{$surface}:ud:{$user->id}")
                            ->response($this->throttledResponse($surface, 'user_daily', $user->id, null));
                    }
                    break;
            }
        }

        return $limits;
    }

    /**
     * 429 responder that first emits a structured observability event. The
     * org-daily hit in particular is an upsell signal, not just an infra log —
     * surface it so commercial tooling can consume it.
     */
    private function throttledResponse(string $surface, string $kind, int $userId, ?string $orgId): \Closure
    {
        return function (Request $request, array $headers) use ($surface, $kind, $userId, $orgId) {
            Log::warning('rate_limit.hit', [
                'surface' => $surface,
                'limit_kind' => $kind,
                'user_id' => $userId,
                'organization_id' => $orgId,
            ]);

            return response()->json([
                'message' => 'Too many requests. Please retry shortly.',
                'retry_after' => $headers['Retry-After'] ?? null,
            ], 429, $headers);
        };
    }
}
