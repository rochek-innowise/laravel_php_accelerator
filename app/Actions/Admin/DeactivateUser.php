<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\UserStatus;
use App\Exceptions\UserLifecycleException;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * FR-017. A one-line status flip — no session bookkeeping of its own. Blocking the next login
 * attempt and ending any live session on its very next request are both already handled by
 * `EnsureAccountRemainsActive` (AD-015), which re-checks `status` on every request; this action
 * only ever needs to change the column it reads.
 */
final class DeactivateUser
{
    public function __construct(protected AuditLogger $auditLogger) {}

    public function handle(User $target): void
    {
        if ($target->status === UserStatus::Deleted) {
            throw UserLifecycleException::alreadyDeleted($target);
        }

        $target->forceFill(['status' => UserStatus::Inactive])->save();

        $this->auditLogger->log('user.deactivated', $target);
    }
}
