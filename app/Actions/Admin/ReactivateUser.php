<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\UserStatus;
use App\Exceptions\UserLifecycleException;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * FR-017. Same guard against `Deleted` as `DeactivateUser` — reactivation of a GDPR-anonymized
 * account must be impossible (BR-018), and this is the second of the two places that enforce it,
 * alongside `UserStatus::Deleted` blocking login outright.
 */
final class ReactivateUser
{
    public function __construct(protected AuditLogger $auditLogger) {}

    public function handle(User $target): void
    {
        if ($target->status === UserStatus::Deleted) {
            throw UserLifecycleException::alreadyDeleted($target);
        }

        $target->forceFill(['status' => UserStatus::Active])->save();

        $this->auditLogger->log('user.reactivated', $target);
    }
}
