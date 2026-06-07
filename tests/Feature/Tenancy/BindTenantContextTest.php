<?php

use App\Http\Middleware\BindTenantContext;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * BindTenantContext must leave the tenant connection fail-closed for guests.
 * With a persistent connection a guest would otherwise inherit the previous
 * request's RLS scope, so the middleware resets it — but only when persistence
 * is enabled, to avoid an extra round-trip otherwise.
 */
function runBindTenantContext(TenantContext $context, ?User $user): void
{
    $request = Request::create('/');
    if ($user !== null) {
        $request->setUserResolver(fn () => $user);
    }

    (new BindTenantContext($context))->handle($request, fn () => new Response);
}

it('binds the authenticated user account scope', function () {
    $user = User::factory()->create();
    $context = Mockery::spy(TenantContext::class);

    runBindTenantContext($context, $user);

    $context->shouldHaveReceived('set')->with($user->organization_id, $user->id);
    $context->shouldNotHaveReceived('forget');
});

it('resets the tenant scope for a guest when the connection is persistent', function () {
    config(['database.tenant_persistent' => true]);
    $context = Mockery::spy(TenantContext::class);

    runBindTenantContext($context, null);

    $context->shouldHaveReceived('forget');
    $context->shouldNotHaveReceived('set');
});

it('leaves the tenant scope untouched for a guest without persistent connections', function () {
    config(['database.tenant_persistent' => false]);
    $context = Mockery::spy(TenantContext::class);

    runBindTenantContext($context, null);

    $context->shouldNotHaveReceived('forget');
    $context->shouldNotHaveReceived('set');
});
