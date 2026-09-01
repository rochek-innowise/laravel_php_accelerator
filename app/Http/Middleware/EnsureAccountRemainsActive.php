<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-017 / FR-018 on every request, not only at login. Checking the status once in the Fortify
 * pipeline leaves a deactivated user inside a live session — up to the full session lifetime, or
 * indefinitely with a remember-me cookie — so the guard has to run per request.
 */
final class EnsureAccountRemainsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status->canLogIn()) {
            return $next($request);
        }

        $this->terminateSession($request);

        if ($request->expectsJson()) {
            abort(401, __(UserStatus::DEACTIVATED_MESSAGE));
        }

        return redirect()->route('login')->withErrors([
            Fortify::username() => __(UserStatus::DEACTIVATED_MESSAGE),
        ]);
    }

    /** Logout cycles the remember token, so the cookie the browser still holds is dead too. */
    protected function terminateSession(Request $request): void
    {
        // Audited before the logout, while there is still an actor to attribute it to (A09).
        app(AuditLogger::class)->log('auth.session_terminated', $request->user());

        Auth::logout();

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
