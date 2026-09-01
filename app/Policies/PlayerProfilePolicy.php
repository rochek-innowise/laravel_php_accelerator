<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\PlayerProfile;
use App\Models\User;

/** Order: tenant membership -> role -> child deny list (AD-005). */
final class PlayerProfilePolicy
{
    public function view(User $user, PlayerProfile $playerProfile): bool
    {
        // TODO(slice-b): also allow a trainer reaching this person through an active
        // trainer_players row in the current tenant.
        return $this->ownsOrIs($user, $playerProfile);
    }

    public function create(User $user): bool
    {
        // A child cannot create profiles; the global deny list covers the association abilities.
        return $user->role === Role::Player && ! $user->is_child_account;
    }

    public function update(User $user, PlayerProfile $playerProfile): bool
    {
        return $this->ownsOrIs($user, $playerProfile);
    }

    public function manageTrainerAssociations(User $user, PlayerProfile $playerProfile): bool
    {
        // FR-009/FR-011: the owning parent only, never the child themselves.
        return $user->id === $playerProfile->owner_user_id && ! $user->is_child_account;
    }

    /** The owning account, or the login this profile itself is backed by. */
    protected function ownsOrIs(User $user, PlayerProfile $playerProfile): bool
    {
        return $user->id === $playerProfile->owner_user_id
            || $user->id === $playerProfile->user_id;
    }
}
