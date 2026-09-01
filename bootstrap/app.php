<?php

use App\Http\Middleware\EnsureAccountRemainsActive;
use App\Http\Middleware\EnsureUserHasRole;
use App\Services\AuditLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        // Appended to `web` rather than to the `auth` group in routes/web.php: Fortify's own
        // authenticated routes (profile, password, verification) and Livewire's update endpoint
        // must be covered too, or a deactivated session keeps mutating data (FR-017/FR-018).
        $middleware->appendToGroup('web', EnsureAccountRemainsActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A09. Both hooks hang off render() rather than report(), because Laravel ignores these
        // exception types for reporting. Returning null leaves the standard response untouched.
        //
        // The type here is AccessDeniedHttpException, not AuthorizationException: render()
        // runs prepareException() first, which has already converted the latter into the former
        // by the time render callbacks are matched. The original message survives the conversion.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request): null {
            app(AuditLogger::class)->log('authorization.denied', $request->user(), [
                'reason' => $e->getMessage(),
                'path' => $request->path(),
            ]);

            return null;
        });

        // A throttled request is the visible half of a credential-stuffing or reset-spam run, and
        // with a custom login limiter configured Fortify never fires the `Lockout` event.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request): null {
            app(AuditLogger::class)->log('request.throttled', $request->user(), [
                'path' => $request->path(),
            ]);

            return null;
        });
    })->create();
