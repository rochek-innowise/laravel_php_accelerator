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
        // TODO(slice-b): also allow any member of this tenant, resolved through TrainerContext.
        return $this->owns($user, $trainerProfile) || $user->isSuperAdmin();
    }

    public function update(User $user, TrainerProfile $trainerProfile): bool
    {
        return $this->owns($user, $trainerProfile);
    }

    public function updateBranding(User $user, TrainerProfile $trainerProfile): bool
    {
        return $this->owns($user, $trainerProfile);
    }

    protected function owns(User $user, TrainerProfile $trainerProfile): bool
    {
        return $user->id === $trainerProfile->user_id;
    }
}
