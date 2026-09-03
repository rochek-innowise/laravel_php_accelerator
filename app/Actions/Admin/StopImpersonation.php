<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\ImpersonationLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The single chokepoint called by both `ImpersonationController::stop()` and
 * `EnforceImpersonationTimeout` on timeout — never a second implementation of "restore and
 * close".
 *
 * Fails closed to the login screen if the admin's own account was deactivated mid-session
 * (Decision 6's explicit consequence): the caller checks the return value and redirects
 * accordingly rather than this action redirecting itself, so both call sites (a controller
 * action and a middleware) can each respond in their own idiom.
 */
final class StopImpersonation
{
    public function __construct(protected AuditLogger $auditLogger) {}

    public function handle(Request $request): bool
    {
        $impersonatorId = $request->session()->get('impersonator_id');
        $logId = $request->session()->get('impersonation_log_id');
        $startedAt = $request->session()->get('impersonation_started_at');

        if (empty($impersonatorId)) {
            return true;
        }

        // Audited against the admin, first, while the session still holds impersonator_id (so
        // the row is still dual-attributed) and while auth()->id() is still the target.
        $this->auditLogger->log('impersonation.stopped', $request->user(), [
            'impersonation_log_id' => $logId,
        ]);

        $this->closeLog($logId, $startedAt);

        $request->session()->forget(['impersonator_id', 'impersonation_log_id', 'impersonation_started_at']);

        // A fresh lookup, not a stale reference, so a mid-impersonation change to the admin's own
        // account (e.g. a deactivation by another Super Admin) is respected.
        $admin = User::find($impersonatorId);

        if (! $admin instanceof User || ! $admin->status->canLogIn()) {
            Auth::logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return false;
        }

        Auth::login($admin);

        return true;
    }

    protected function closeLog(mixed $logId, mixed $startedAt): void
    {
        $log = empty($logId) ? null : ImpersonationLog::find($logId);

        if (! $log instanceof ImpersonationLog || $log->ended_at !== null) {
            return;
        }

        $endedAt = now();
        $startedAtCarbon = is_string($startedAt) ? Carbon::parse($startedAt) : $log->started_at;

        // getTimestamp() difference, not diffInSeconds(): Carbon 3 made the latter signed and
        // fractional by default, which would write a negative, non-integer value into an
        // unsigned integer column.
        $log->forceFill([
            'ended_at' => $endedAt,
            'duration_seconds' => abs($endedAt->getTimestamp() - $startedAtCarbon->getTimestamp()),
        ])->save();
    }
}
