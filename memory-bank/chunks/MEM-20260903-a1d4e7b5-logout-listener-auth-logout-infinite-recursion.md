---
{
  "id": "MEM-20260903-a1d4e7b5",
  "title": "A Logout listener that calls an action which itself calls Auth::logout() recurses infinitely",
  "type": "constraint",
  "status": "active",
  "scope": ["application"],
  "tags": ["authentication", "events", "impersonation", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["app/Listeners/StopImpersonationOnLogout.php", "app/Actions/Admin/StopImpersonation.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# Logout Listener That Calls Auth::logout() Recurses Infinitely

## Durable Context

When a `Logout` event listener calls an action that calls `Auth::logout()`, the logout event fires again, re-entering the listener with session keys still present—creating infinite recursion.

**The scenario:** `StopImpersonationOnLogout` listens on `Illuminate\Auth\Events\Logout`, calling `StopImpersonation::handle()` to clean up impersonation state. The fail-closed branch of that action calls `Auth::logout()` again if the admin's account was deactivated mid-session. That inner logout fires the `Logout` event, re-entering the listener, which sees the impersonator session keys still intact and calls `Auth::logout()` again, and so on.

**The fix:** Clear the session keys *before* calling `Auth::logout()` in the fail-closed branch, not after. The second invocation of the listener then sees an empty `impersonator_id` and returns early.

## Consequences

- Any listener on `Logout` that calls `Auth::logout()` directly or indirectly must clear its state *before* the inner logout, not after.
- Use an `empty()` guard at the top of the listener/action to bail out early on re-entrance.
- Pattern:

```php
public function handle(Request $request): bool
{
    $impersonatorId = $request->session()->get('impersonator_id');

    if (empty($impersonatorId)) {
        return true;  // Already cleared, nothing to do
    }

    // ... do work, close logs, etc. ...

    // Clear state BEFORE Auth::logout()
    $request->session()->forget(['impersonator_id', 'impersonation_log_id', ...]);

    // Safe to call now; any re-entrance will see empty keys and exit early
    Auth::logout();

    return $someBoolean;
}
```

- If the listener needs to read session keys, do it at the start and store locally; don't rely on a second read after `Auth::logout()`.

## Verification

From `app/Listeners/StopImpersonationOnLogout.php`:

```php
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
```

From `app/Actions/Admin/StopImpersonation.php`, the fail-closed branch:

```php
if (! $admin instanceof User || ! $admin->status->canLogIn()) {
    // Forgotten *before* Auth::logout(), unlike the success path below: Auth::logout()
    // fires the Logout event, which StopImpersonationOnLogout (finding 4) listens on to
    // call this very method again. Left in place, that reentrant call would still see
    // impersonator_id, fail closed the same way, call Auth::logout() again, and recurse
    // without end. Forgetting first makes the reentrant call's own `empty($impersonatorId)`
    // guard at the top of this method end the recursion on its first no-op return.
    $request->session()->forget(['impersonator_id', 'impersonation_log_id', 'impersonation_started_at']);

    Auth::logout();

    // ... invalidate session ...

    return false;
}
```

The fix clears session state before the inner `Auth::logout()`, making the listener's early return guard effective.
