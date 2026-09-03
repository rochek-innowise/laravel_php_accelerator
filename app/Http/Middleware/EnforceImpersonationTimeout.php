<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Admin\StopImpersonation;
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

        if (! $expired) {
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

        return redirect()->route('dashboard')->with(
            'status',
            __('Impersonation session expired after 60 minutes.'),
        );
    }
}
