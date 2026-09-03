<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Admin\StopImpersonation;
use Illuminate\Auth\Events\Logout;

/**
 * Finding 4 (Slice D). Nothing else clears impersonation state on a plain logout:
 * `SessionGuard::logout()` only removes the guard's own session key
 * (`clearUserDataFromStorage()`) before firing this event — the impersonation session keys
 * (`impersonator_id` and friends) are still present at this exact point, and only get flushed a
 * few lines later when Fortify's `AuthenticatedSessionController::destroy()` calls
 * `$request->session()->invalidate()`. So this listener, firing synchronously inside
 * `guard->logout()`, is the last moment `StopImpersonation::handle()` can still read them.
 *
 * Without this, the `ImpersonationLog` row is left open (`ended_at = null`) until
 * `CloseStaleImpersonationLogsJob` closes it 60 minutes later with a hard `duration_seconds =
 * 3600` — a 20-second session reported as a full hour, indistinguishable in the compliance report
 * from a genuinely abandoned tab. The nav "Log out" sits directly above the impersonation banner,
 * so this is the normal path, not an edge case.
 *
 * `StopImpersonation::handle()` is already idempotent (no-ops on an absent `impersonator_id`;
 * `closeLog()` refuses an already-closed row), so it is called unconditionally here — the only
 * guard needed is that a session exists to read from at all.
 */
final class StopImpersonationOnLogout
{
    public function __construct(protected StopImpersonation $stopImpersonation) {}

    public function handle(Logout $event): void
    {
        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        $this->stopImpersonation->handle($request);
    }
}
