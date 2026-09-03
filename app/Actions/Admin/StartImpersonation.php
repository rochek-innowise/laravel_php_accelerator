<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\ImpersonationLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Slice D Decision 6's start flow. The action owns the guard, not the controller (BR-016 —
 * "guard the Super Admin rule" is part of the action, per the Requirements table's own phrasing).
 *
 * The ordering below is the design's single most security-critical line, restated here so it is
 * not silently reordered later: create the log, write the session keys, only *then*
 * `Auth::login($target)` — never `Auth::logout()` first. Logout flushes the session and would
 * destroy the very keys just written. `Auth::login()` regenerates the session id while preserving
 * session data, which is the behaviour this order depends on.
 */
final class StartImpersonation
{
    public function __construct(protected AuditLogger $auditLogger) {}

    public function handle(Request $request, User $admin, User $target): void
    {
        Gate::authorize('impersonate', $target);

        // forceFill: ImpersonationLog carries no #[Fillable] allow-list, mirroring AuditLog — the
        // trail must not be forgeable through a stray mass assignment somewhere else.
        $log = (new ImpersonationLog)->forceFill([
            'admin_user_id' => $admin->id,
            'target_user_id' => $target->id,
            'started_at' => now(),
            'ip_address' => $request->ip(),
        ]);
        $log->save();

        $request->session()->put([
            'impersonator_id' => $admin->id,
            'impersonation_log_id' => $log->id,
            'impersonation_started_at' => now()->toISOString(),
        ]);

        // Never Auth::logout() first — see the class docblock.
        Auth::login($target);

        // Dual-attributed: impersonator_id is already in the session by the time this line runs.
        $this->auditLogger->log('impersonation.started', $target, [
            'impersonation_log_id' => $log->id,
        ]);
    }
}
