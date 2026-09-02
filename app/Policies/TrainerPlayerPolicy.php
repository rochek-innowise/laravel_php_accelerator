<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\TrainerPlayer;
use App\Models\User;

/** Order: tenant membership -> role -> child deny list (AD-005). */
final class TrainerPlayerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === Role::Trainer || $user->role === Role::Coach;
    }

    /** Either side of the association may see it: the organisation, or the family it concerns. */
    public function view(User $user, TrainerPlayer $association): bool
    {
        return $this->belongsToTheOrganisation($user, $association)
            || $this->isTheFamily($user, $association);
    }

    /** FR-009: only a guardian adds or removes a child's associations, and never a child login. */
    public function manage(User $user, TrainerPlayer $association): bool
    {
        if ($user->is_child_account) {
            return false;
        }

        return $this->isTheFamily($user, $association);
    }

    public function delete(User $user, TrainerPlayer $association): bool
    {
        return $this->manage($user, $association)
            || $this->belongsToTheOrganisation($user, $association);
    }

    protected function belongsToTheOrganisation(User $user, TrainerPlayer $association): bool
    {
        if ($user->role !== Role::Trainer) {
            return false;
        }

        return $user->trainerProfile?->getKey() === $association->trainer_profile_id;
    }

    protected function isTheFamily(User $user, TrainerPlayer $association): bool
    {
        return $user->trainableProfiles()
            ->contains(fn ($profile): bool => $profile->getKey() === $association->player_profile_id);
    }
}
