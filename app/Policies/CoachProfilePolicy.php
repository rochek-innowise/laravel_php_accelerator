<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\CoachProfile;
use App\Models\User;
use App\Support\Tenancy\TrainerContext;

/** Order: tenant membership -> role -> child deny list (AD-005). */
final class CoachProfilePolicy
{
    /**
     * The listing itself is scoped by `TenantScope`, so this answers the other half: may this
     * actor open the screen at all, and is the organisation on screen their own?
     */
    public function viewAny(User $user): bool
    {
        if ($user->role !== Role::Trainer) {
            return false;
        }

        // The tenancy branch, and nothing more: a resolved organisation that is not this
        // trainer's own is refused. Whether the trainer has a profile at all is a data-integrity
        // question, not an authorisation one — the action fails on its own if they do not.
        $tenantId = app(TrainerContext::class)->id();

        return $tenantId === null || $tenantId === $user->trainerProfile?->getKey();
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
