<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Admin\StopImpersonation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the `web` group between `EnsureAccountRemainsActive` and `EnsureTrainerContext` —
 * skipping a wasted tenant resolution on a request about to be torn down. No-ops if
 * `impersonator_id` is absent. Expiry is necessarily *passive*: no scheduled job can reach into a
 * live session, so this is the only place a running impersonation is ever force-stopped by a
 * live request. `CloseStaleImpersonationLogsJob` is the safety net for a tab that never sends
 * another request at all.
 *
 * Finding 6 (Slice D): also the only place BR-016 ("never a Super Admin as the impersonated
 * target") can be *re*-asserted. `UserPolicy::impersonate` only checks it once, at start — if a
 * second Super Admin promotes the live impersonated target mid-session (a legitimate operation via
 * `EditUserForm`), nothing else ever looks again, and the impersonated session silently becomes a
 * Super Admin session. This middleware sees every request, so it force-stops through the same
 * `StopImpersonation` chokepoint the instant the effective user turns out to be a Super Admin,
 * exactly as it already does for a timed-out session.
 */
final class EnforceImpersonationTimeout
{
    public const TIMEOUT_MINUTES = 60;

    public function __construct(protected StopImpersonation $stopImpersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has('impersonator_id')) {
            return $next($request);
        }

        $startedAt = $request->session()->get('impersonation_started_at');
        $expired = ! is_string($startedAt)
            || Carbon::parse($startedAt)->addMinutes(self::TIMEOUT_MINUTES)->isPast();

        // A fresh lookup, not the guard's cached user object — the same reasoning
        // StopImpersonation::handle() already documents for re-fetching the admin: a role change
        // made by a second Super Admin mid-session must be seen on the very next request.
        $targetId = $request->user()?->getAuthIdentifier();
        $becameSuperAdmin = $targetId !== null && (User::find($targetId)?->isSuperAdmin() ?? false);

        if (! $expired && ! $becameSuperAdmin) {
            return $next($request);
        }

        // Same chokepoint the manual-stop controller calls — not a second implementation of
        // "restore and close".
        $adminRestored = $this->stopImpersonation->handle($request);

        if (! $adminRestored) {
            return redirect()->route('login')->withErrors([
                'email' => __('Your account is no longer active. Please contact support.'),
            ]);
        }

        $message = $becameSuperAdmin
            ? __('Impersonation ended: the account you were viewing became a Super Admin.')
            : __('Impersonation session expired after 60 minutes.');

        return redirect()->route('dashboard')->with('status', $message);
    }
}
