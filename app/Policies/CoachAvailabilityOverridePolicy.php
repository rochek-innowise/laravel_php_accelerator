<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\CoachProfile;
use App\Models\User;

/**
 * FR-015. Built now, called by nothing until Epic-02 wires an event-assignment flow to
 * `OverrideCoachAvailability` — the same "seam built in full, unwired" treatment as
 * `ApprovedPurchaseExecutor`.
 */
final class CoachAvailabilityOverridePolicy
{
    public function create(User $user, CoachProfile $coach): bool
    {
        return $user->role === Role::Trainer && $user->trainerProfile?->id === $coach->trainer_profile_id;
    }
}
