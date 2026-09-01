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
    public function log(string $action, ?Model $subject = null, array $metadata = []): AuditLog
    {
        // TODO(coder): resolve actor from auth(), on_behalf_of from session('impersonator_id'),
        // capture the request IP, and persist.
        throw new \RuntimeException('Not implemented');
    }
}
