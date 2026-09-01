<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * NFR-011 / OWASP A09. Without these the trail is blind exactly where it matters: a credential
 * stuffing or password-reset run leaves nothing behind. The attempted address is recorded because
 * that is the point of the trail; the submitted password never is — `Failed` carries it in
 * $credentials, so the payload is picked apart by hand rather than passed through.
 *
 * Methods are named `audit*`, not `handle*`: Laravel discovers listeners in this directory by the
 * `handle` prefix, which would register every one of them a second time on top of the explicit
 * wiring in AppServiceProvider. Throttle lockouts are audited from the exception layer instead —
 * with a custom login limiter configured, Fortify never reaches the action that fires `Lockout`.
 */
final class AuditAuthenticationEvents
{
    public function __construct(protected AuditLogger $auditLogger) {}

    public function auditLogin(Login $event): void
    {
        $this->auditLogger->log('auth.login', $event->user instanceof User ? $event->user : null);
    }

    public function auditLogout(Logout $event): void
    {
        $this->auditLogger->log('auth.logout', $event->user instanceof User ? $event->user : null);
    }

    public function auditFailed(Failed $event): void
    {
        $this->auditLogger->log('auth.failed', $event->user instanceof User ? $event->user : null, [
            'email' => $event->credentials['email'] ?? null,
        ]);
    }
}
