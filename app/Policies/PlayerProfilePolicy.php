<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlayerProfile;
use App\Models\User;

/** Order: tenant membership -> role -> child deny list (AD-005). */
final class PlayerProfilePolicy
{
    public function view(User $user, PlayerProfile $playerProfile): bool
    {
        // TODO(coder): the owner, the profile's own login, a trainer reaching it through an
        // active trainer_players row in the current tenant, or Super Admin.
        throw new \RuntimeException('Not implemented');
    }

    public function create(User $user): bool
    {
        // TODO(coder): a Player/Parent account creating a child (FR-008). Slice C.
        throw new \RuntimeException('Not implemented');
    }

    public function update(User $user, PlayerProfile $playerProfile): bool
    {
        // TODO(coder): the owner; a child may edit only the basic fields of FR-011.
        throw new \RuntimeException('Not implemented');
    }

    public function manageTrainerAssociations(User $user, PlayerProfile $playerProfile): bool
    {
        // TODO(coder): the owning parent only — children are denied (FR-009/FR-011). Slice C.
        throw new \RuntimeException('Not implemented');
    }
}
