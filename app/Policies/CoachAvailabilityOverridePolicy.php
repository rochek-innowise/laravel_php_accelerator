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
 *
 * Whoever wires that flow must call this policy directly (`app(CoachAvailabilityOverridePolicy::class)
 * ->create($user, $coach)`), not `$this->authorize('create', $coach)`: `CoachProfile` already has
 * its own registered policy (`CoachProfilePolicy`, Slice A), and Laravel's Gate resolves an
 * ability purely from the subject's model class — `$user->can('create', $coachProfile)` would
 * silently hit `CoachProfilePolicy`, not this class (see `AvailabilityPolicy`'s own note).
 */
final class CoachAvailabilityOverridePolicy
{
    public function create(User $user, CoachProfile $coach): bool
    {
        return $user->role === Role::Trainer && $user->trainerProfile?->id === $coach->trainer_profile_id;
    }
}
