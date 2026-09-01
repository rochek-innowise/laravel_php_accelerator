<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\CoachProfile;
use App\Models\User;

/** Order: tenant membership -> role -> child deny list (AD-005). */
final class CoachProfilePolicy
{
    public function viewAny(User $user): bool
    {
        // TODO(slice-b): scope the listing to the current tenant once TrainerContext exists.
        return $user->role === Role::Trainer;
    }

    public function view(User $user, CoachProfile $coachProfile): bool
    {
        return $user->id === $coachProfile->user_id || $this->employs($user, $coachProfile);
    }

    public function invite(User $user): bool
    {
        return $user->role === Role::Trainer;
    }

    public function update(User $user, CoachProfile $coachProfile): bool
    {
        return $user->id === $coachProfile->user_id || $this->employs($user, $coachProfile);
    }

    /** The trainer this coach works for — BR-006 guarantees there is at most one active. */
    protected function employs(User $user, CoachProfile $coachProfile): bool
    {
        if ($user->role !== Role::Trainer) {
            return false;
        }

        return $user->trainerProfile?->id === $coachProfile->trainer_profile_id;
    }
}
