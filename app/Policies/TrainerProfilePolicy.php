<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TrainerProfile;
use App\Models\User;

/** Order: tenant membership -> role -> child deny list (AD-005). */
final class TrainerProfilePolicy
{
    public function view(User $user, TrainerProfile $trainerProfile): bool
    {
        // TODO(coder): owner, a member of this tenant, or Super Admin.
        throw new \RuntimeException('Not implemented');
    }

    public function update(User $user, TrainerProfile $trainerProfile): bool
    {
        // TODO(coder): owning trainer only.
        throw new \RuntimeException('Not implemented');
    }

    public function updateBranding(User $user, TrainerProfile $trainerProfile): bool
    {
        // TODO(coder): owning trainer only (FR-019). Slice D.
        throw new \RuntimeException('Not implemented');
    }
}
