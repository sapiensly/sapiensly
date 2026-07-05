<?php

use App\Http\Middleware\BindTenantContext;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InjectAiProviderConfig;
use App\Http\Middleware\RejectBlockedUsers;
use App\Http\Middleware\ResolveTenantConnection;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetPermissionsTeam;
use App\Http\Middleware\VerifyWhatsAppSignature;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Psr\Log\LogLevel;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware([])
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            RejectBlockedUsers::class,
            HandleAppearance::class,
            SetLocale::class,
            SetPermissionsTeam::class,
            BindTenantContext::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            InjectAiProviderConfig::class,
            ResolveTenantConnection::class,
        ]);

        // SubstituteBindings is the last middleware in the default `web` group,
        // so appended middleware run AFTER it. Route model binding for an
        // RLS-protected tenant model (e.g. `{chat}`) would then query before
        // BindTenantContext set the scope — fail-closed, yielding a spurious
        // 404 in production. Pin the tenant scope + permission team ahead of
        // SubstituteBindings via the middleware priority list.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            SetPermissionsTeam::class,
            BindTenantContext::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'verify.whatsapp.signature' => VerifyWhatsAppSignature::class,
        ]);

        // Enable CORS for API routes (widget endpoints)
        $middleware->api(prepend: [
            HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A job killed by its wall-clock timeout surfaces as MaxAttemptsExceeded
        // on redelivery — for the AI turn jobs that's the RESCUE path working
        // (failed() banks the checkpoint and auto-resumes), not an incident.
        // Keep it in the log, but not at ERROR.
        $exceptions->level(MaxAttemptsExceededException::class, LogLevel::WARNING);
        $exceptions->level(TimeoutExceededException::class, LogLevel::WARNING);
    })->create();
