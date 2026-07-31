<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * Runs a callback once per tenant, with that tenant's scope established.
 *
 * This exists because of a failure mode that is completely silent: a scheduled
 * command has no request and therefore no tenant scope, the tenant connection
 * runs as `tenant_app`, and RLS fails closed — so every query over a tenant
 * table returns ZERO rows. The command succeeds, reports "0 pruned", and looks
 * healthy forever while doing nothing at all.
 *
 * Scopes are enumerated from a PLATFORM table (apps, integrations, …), which is
 * readable without a scope because its isolation is structural. Each distinct
 * (organization_id, user_id) pair there is exactly one tenant key, so iterating
 * them covers organization-mode and personal-mode owners alike.
 */
final class TenantScopes
{
    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $source  a PLATFORM model query carrying the tenant key
     * @param  callable(?string, ?int): void  $callback  run once per scope, inside it
     */
    public static function each(Builder $source, callable $callback): void
    {
        $context = app(TenantContext::class);

        $scopes = $source
            ->getQuery()
            ->select('organization_id', 'user_id')
            ->distinct()
            ->get();

        foreach ($scopes as $scope) {
            $organizationId = $scope->organization_id ?? null;
            $userId = $scope->user_id !== null ? (int) $scope->user_id : null;

            // A row with neither key belongs to no tenant and would set an
            // empty scope, which fails closed anyway — skip rather than
            // pretend.
            if ($organizationId === null && $userId === null) {
                continue;
            }

            $context->set($organizationId, $userId);

            try {
                $callback($organizationId, $userId);
            } finally {
                // Never leak one tenant's scope into the next iteration.
                $context->forget();
            }
        }
    }
}
