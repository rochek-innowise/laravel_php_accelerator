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
        // FR-009/FR-011: a guardian, or the account managing its own self profile (a parent who
        // also trains, per `Overview::authorizedChild()`'s self branch) — but never a child login,
        // even for their own profile. Kept in agreement with that method on purpose: this is also
        // what `/family`'s view gates its add/remove controls on, so the two must not disagree
        // about who the self-profile row belongs to.
        if ($user->is_child_account) {
            return false;
        }

        return $playerProfile->isGuardedBy($user) || $user->id === $playerProfile->user_id;
    }

    /** A guardian of this person, or the login the profile itself is backed by. */
    protected function ownsOrIs(User $user, PlayerProfile $playerProfile): bool
    {
        return $user->id === $playerProfile->user_id
            || $playerProfile->isGuardedBy($user);
    }
}
