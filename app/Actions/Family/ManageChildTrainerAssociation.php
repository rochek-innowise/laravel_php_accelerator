<?php

declare(strict_types=1);

namespace App\Actions\Family;

use App\Models\TrainerPlayer;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * FR-009's removal. A soft delete — history preserved, and the `(trainer_profile_id,
 * player_profile_id, deleted_at)` unique index built in Slice B already permits a later
 * re-association without colliding on it.
 *
 * The RSVP-cancellation warning in FR-009's acceptance has nothing to act on yet: no RSVP model
 * exists before Epic-02, so this is a documented no-op rather than invented logic. Authorization is
 * the call site's job (`TrainerPlayerPolicy::delete`), not this action's — it runs unconditionally
 * once called, the same division `AssociatePlayersWithTrainer` already draws.
 */
final class ManageChildTrainerAssociation
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function remove(TrainerPlayer $association, User $actor): void
    {
        $association->delete();

        $this->auditLogger->log('trainer-player.removed', $association, [
            'guardian_user_id' => $actor->getKey(),
            'trainer_profile_id' => $association->trainer_profile_id,
            'player_profile_id' => $association->player_profile_id,
        ]);
    }
}
