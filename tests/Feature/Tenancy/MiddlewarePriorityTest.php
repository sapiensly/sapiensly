<?php

use App\Http\Middleware\BindTenantContext;
use App\Http\Middleware\SetPermissionsTeam;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;

/**
 * Route model binding for RLS-protected tenant models (e.g. `{chat}`) queries
 * the tenant connection during SubstituteBindings. The tenant scope must be set
 * before that runs, or the binding is fail-closed under RLS and 404s in
 * production. Guards the middleware order that makes that true.
 */
it('runs the tenant scope and permission team before route model binding', function () {
    $priority = app(Kernel::class)->getMiddlewarePriority();

    $tenant = array_search(BindTenantContext::class, $priority, true);
    $team = array_search(SetPermissionsTeam::class, $priority, true);
    $bindings = array_search(SubstituteBindings::class, $priority, true);

    expect($tenant)->not->toBeFalse('BindTenantContext must be in the priority list')
        ->and($bindings)->not->toBeFalse('SubstituteBindings must be in the priority list')
        ->and($tenant)->toBeLessThan($bindings)
        ->and($team)->toBeLessThan($bindings);
});
