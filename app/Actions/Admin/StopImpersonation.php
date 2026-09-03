<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Http\Middleware\EnforceImpersonationTimeout;
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

        $log = $this->closeLog($logId, $startedAt);

        // Finding 8 (hardening): the row already loaded by closeLog() must actually belong to
        // this admin/target pair, not just whatever the session claims. Not exploitable today —
        // StartImpersonation is the only writer and sessions are server-side — but a free second
        // gate. Fails closed (same as a deactivated admin, below) if it doesn't match.
        $rowMatches = $log instanceof ImpersonationLog
            && $log->admin_user_id === (int) $impersonatorId
            && $log->target_user_id === (int) $request->user()?->getAuthIdentifier();

        // A fresh lookup, not a stale reference, so a mid-impersonation change to the admin's own
        // account (e.g. a deactivation by another Super Admin) is respected.
        $admin = $rowMatches ? User::find($impersonatorId) : null;

        if (! $admin instanceof User || ! $admin->status->canLogIn()) {
            // Forgotten *before* Auth::logout(), unlike the success path below: Auth::logout()
            // fires the Logout event, which StopImpersonationOnLogout (finding 4) listens on to
            // call this very method again. Left in place, that reentrant call would still see
            // impersonator_id, fail closed the same way, call Auth::logout() again, and recurse
            // without end. Forgetting first makes the reentrant call's own `empty($impersonatorId)`
            // guard at the top of this method end the recursion on its first no-op return.
            $request->session()->forget(['impersonator_id', 'impersonation_log_id', 'impersonation_started_at']);

            Auth::logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return false;
        }

        Auth::login($admin);

        // Finding 8 (hardening): forgotten only now that the admin is safely restored. The four
        // steps here are not atomic — forgetting these first and having Auth::login() throw would
        // leave an un-flagged, un-bannered, un-guardrailed session acting as the target with no
        // way back.
        $request->session()->forget(['impersonator_id', 'impersonation_log_id', 'impersonation_started_at']);

        return true;
    }

    protected function closeLog(mixed $logId, mixed $startedAt): ?ImpersonationLog
    {
        $log = empty($logId) ? null : ImpersonationLog::find($logId);

        if (! $log instanceof ImpersonationLog) {
            return null;
        }

        if ($log->ended_at !== null) {
            return $log;
        }

        $endedAt = now();
        $startedAtCarbon = is_string($startedAt) ? Carbon::parse($startedAt) : $log->started_at;

        // getTimestamp() difference, not diffInSeconds(): Carbon 3 made the latter signed and
        // fractional by default, which would write a negative, non-integer value into an
        // unsigned integer column.
        //
        // Finding 7 (Slice D): clamped to the timeout ceiling, so this path and
        // CloseStaleImpersonationLogsJob's always agree on what gets recorded for the same kind of
        // session — a session that ran (or was merely abandoned) past 60 minutes is reported as
        // exactly 60 minutes either way, never the raw elapsed wall-clock time. Without this, an
        // idle tab whose next request lands 25 hours later took this path and recorded 90000
        // where the job would have recorded 3600 for the identical session — and which one won
        // was a race with the 15-minute scheduler. It also keeps
        // impersonation-history.blade.php's `gmdate('H:i:s', $duration)` safe: that format wraps
        // at 86400 seconds, so an unclamped value could render as a misleadingly small duration.
        $elapsedSeconds = abs($endedAt->getTimestamp() - $startedAtCarbon->getTimestamp());
        $timeoutCeilingSeconds = EnforceImpersonationTimeout::TIMEOUT_MINUTES * 60;

        $log->forceFill([
            'ended_at' => $endedAt,
            'duration_seconds' => min($elapsedSeconds, $timeoutCeilingSeconds),
        ])->save();

        return $log;
    }
}
