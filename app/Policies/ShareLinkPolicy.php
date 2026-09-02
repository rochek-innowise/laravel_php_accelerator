<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\ShareLink;
use App\Models\User;
use App\Support\Tenancy\TrainerContext;

/** Order: tenant membership -> role -> child deny list (AD-005). */
final class ShareLinkPolicy
{
    public function __construct(protected TrainerContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->ownsTheCurrentOrganisation($user);
    }

    public function view(User $user, ShareLink $shareLink): bool
    {
        return $this->owns($user, $shareLink);
    }

    public function create(User $user): bool
    {
        return $this->ownsTheCurrentOrganisation($user);
    }

    public function update(User $user, ShareLink $shareLink): bool
    {
        return $this->owns($user, $shareLink);
    }

    public function delete(User $user, ShareLink $shareLink): bool
    {
        return $this->owns($user, $shareLink);
    }

    /**
     * Tenancy first, and it is not redundant with the global scope: the scope decides what a query
     * *returns*, this decides what an actor may *do* with a row already in hand. A check that
     * passes on role but fails on tenancy is precisely the NFR-010 breach.
     */
    protected function owns(User $user, ShareLink $shareLink): bool
    {
        if ($user->role !== Role::Trainer) {
            return false;
        }

        return $user->trainerProfile?->getKey() === $shareLink->trainer_profile_id;
    }

    protected function ownsTheCurrentOrganisation(User $user): bool
    {
        if ($user->role !== Role::Trainer) {
            return false;
        }

        // A resolved organisation that is not this trainer's own is refused; an unresolved one
        // falls through to the action, which cannot mint a link without a profile anyway.
        $tenantId = $this->context->id();

        return $tenantId === null || $tenantId === $user->trainerProfile?->getKey();
    }
}
