<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Order: tenant membership -> role -> child deny list (AD-005).
 *
 * The subject is the profile whose Best Times are being set, never an `Availability` row itself —
 * the same shape `PlayerProfilePolicy`/`CoachProfilePolicy` already use for their own `update`.
 *
 * Gap 8: there is no `Gate::policy` registration, so Laravel's convention discovery auto-binds
 * this class to `App\Models\Availability` by name alone. The parameter type is widened to `Model`
 * (rather than the narrower `PlayerProfile|CoachProfile` this policy actually reasons about) so
 * that a future `authorize('update', $availabilityRow)` reaching this class by that accident gets
 * a plain `false` (403) from the `match` below, not a `TypeError` (500) — a worse failure than the
 * ambiguity the direct-invocation call sites already work around.
 */
final class AvailabilityPolicy
{
    public function update(User $user, Model $subject): bool
    {
        return match (true) {
            $subject instanceof CoachProfile => $user->id === $subject->user_id,
            // Not excluded for a child login, unlike `manageTrainerAssociations`:
            // `ChildAbilities::DENIED` does not name availability, since FR-014 frames this as the
            // child's own preference data, not a trainer-association decision.
            $subject instanceof PlayerProfile => $user->id === $subject->user_id || $subject->isGuardedBy($user),
            default => false,
        };
    }
}
