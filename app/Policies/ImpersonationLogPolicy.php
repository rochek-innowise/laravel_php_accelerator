<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** FR-012's compliance report — a Super Admin only, no per-row ownership to check. */
final class ImpersonationLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
