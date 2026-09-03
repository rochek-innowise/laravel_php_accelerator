<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\User;

/**
 * Order: tenant membership -> role -> child deny list (AD-005).
 *
 * The subject is the profile whose Best Times are being set, never an `Availability` row itself —
 * the same shape `PlayerProfilePolicy`/`CoachProfilePolicy` already use for their own `update`.
 */
final class AvailabilityPolicy
{
    public function update(User $user, PlayerProfile|CoachProfile $subject): bool
    {
        if ($subject instanceof CoachProfile) {
            return $user->id === $subject->user_id;
        }

        // Not excluded for a child login, unlike `manageTrainerAssociations`:
        // `ChildAbilities::DENIED` does not name availability, since FR-014 frames this as the
        // child's own preference data, not a trainer-association decision.
        return $user->id === $subject->user_id || $subject->isGuardedBy($user);
    }
}
