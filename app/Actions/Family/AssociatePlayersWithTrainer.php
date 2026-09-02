<?php

declare(strict_types=1);

namespace App\Actions\Family;

use App\Enums\TrainerPlayerStatus;
use App\Models\PlayerProfile;
use App\Models\ShareLink;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Support\Collection;

/**
 * FR-007 / BR-023: associations are explicit. The parent ticks who joins, and nothing else joins.
 *
 * The submitted ids are treated as a *request*, not as a decision: the permitted set is re-derived
 * from the account's own family server-side and anything outside it is dropped silently. That
 * mirrors the guardianship handling Slice A already pins in `RoleSpecificProfileFieldsTest` — a
 * forged profile id in the checklist must be inert, not an error the client can probe.
 */
final class AssociatePlayersWithTrainer
{
    public function __construct(protected AuditLogger $auditLogger, protected TrainerContext $context) {}

    /**
     * @param  list<int>  $requestedProfileIds
     * @return Collection<int, TrainerPlayer>
     */
    public function handle(
        TrainerProfile $trainer,
        User $actor,
        array $requestedProfileIds,
        ?ShareLink $via = null,
    ): Collection {
        $permitted = $actor->trainableProfiles()
            ->whereIn('id', $requestedProfileIds)
            ->values();

        return $this->context->runFor($trainer, function () use ($trainer, $permitted, $via): Collection {
            return $permitted
                ->map(fn (PlayerProfile $profile): TrainerPlayer => $this->associate($trainer, $profile, $via))
                ->values();
        });
    }

    /**
     * Idempotent by BR-007: joining twice is a no-op, never a duplicate row and never a duplicate
     * account. An association that was deactivated is reactivated in place rather than duplicated;
     * a *soft-deleted* one would not be found here at all, and the unique index counts only live
     * rows, so a fresh row could be written beside it. Nothing in Slice B soft-deletes an
     * association yet — removal arrives with the family screen in Slice C.
     */
    protected function associate(TrainerProfile $trainer, PlayerProfile $profile, ?ShareLink $via): TrainerPlayer
    {
        $existing = $trainer->trainerPlayers()
            ->where('player_profile_id', $profile->getKey())
            ->first();

        if ($existing !== null) {
            if (! $existing->isActive()) {
                $existing->update(['status' => TrainerPlayerStatus::Active]);
            }

            return $existing;
        }

        $association = new TrainerPlayer(['status' => TrainerPlayerStatus::Active]);

        // Ownership columns are never mass-assignable (AD-016): a request-supplied
        // trainer_profile_id is exactly the cross-organisation write NFR-010 forbids.
        $association->forceFill([
            'trainer_profile_id' => $trainer->getKey(),
            'player_profile_id' => $profile->getKey(),
            'share_link_id' => $via?->getKey(),
            'connected_at' => now(),
        ])->save();

        $this->auditLogger->log('trainer-player.associated', $association, [
            'trainer_profile_id' => $trainer->getKey(),
            'player_profile_id' => $profile->getKey(),
            'share_link_id' => $via?->getKey(),
        ]);

        return $association;
    }
}
