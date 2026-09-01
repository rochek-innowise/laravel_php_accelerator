<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the audit trail required by NFR-011. Records both identities: the acting user, and the
 * Super Admin behind an impersonated write (session key `impersonator_id`, Slice D).
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(string $action, ?Model $subject = null, array $metadata = []): AuditLog
    {
        $request = request();

        // forceFill, because AuditLog guards every attribute — the trail must not be forgeable
        // through a stray mass assignment somewhere else in the application.
        $log = (new AuditLog)->forceFill([
            'actor_user_id' => auth()->id(),
            'on_behalf_of_user_id' => $this->impersonatorId(),
            'action' => $action,
            'ip_address' => $request->ip(),
            'metadata' => empty($metadata) ? null : $metadata,
        ]);

        if (! empty($subject)) {
            $log->subject()->associate($subject);
        }

        $log->save();

        return $log;
    }

    protected function impersonatorId(): ?int
    {
        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        return $request->session()->get('impersonator_id');
    }
}
