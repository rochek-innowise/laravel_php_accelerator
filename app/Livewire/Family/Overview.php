<?php

declare(strict_types=1);

namespace App\Livewire\Family;

use App\Actions\Family\AssociatePlayersWithTrainer;
use App\Actions\Family\ManageChildTrainerAssociation;
use App\Actions\ShareLink\RedeemShareLink as RedeemAction;
use App\Enums\TrainerPlayerStatus;
use App\Exceptions\ShareLinkNotRedeemableException;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * FR-009. Both "add" paths reuse Slice B actions unmodified (see the Slice C plan's Existing
 * Context table); "remove" is the one genuinely new piece here.
 */
final class Overview extends Component
{
    /** @var array<int, string> keyed by child id — a separate manual-code field per child row. */
    public array $manualCode = [];

    /** @var array<int, int|null> keyed by child id — the trainer picker per child row. */
    public array $pickerTrainerId = [];

    /**
     * Every association across the acting user's whole family, tenant-blind by construction: each
     * is reached through `PlayerProfile::trainerAssociations()`, an identity relation, never a raw
     * `TrainerPlayer::query()` (AD-001 / AD-003 — that would be an unreviewed third escape from
     * TenantScope, not the two documented ones).
     *
     * @return Collection<int, TrainerPlayer>
     */
    protected function familyAssociations(): Collection
    {
        return $this->actor()->trainableProfiles()
            ->flatMap(fn (PlayerProfile $profile): Collection => $profile->trainerAssociations()
                ->with('trainerProfile')
                ->orderByDesc('connected_at')
                ->get());
    }

    /**
     * Decision 4: the union of trainers already reachable by any of the family's own members. The
     * picker offers only these — a brand-new organisation still needs the manual-code path, which
     * is exactly BR-023's "associations are explicit," not silently inferred.
     *
     * @return Collection<int, TrainerProfile>
     */
    protected function familyTrainerProfiles(): Collection
    {
        return $this->familyAssociations()
            ->where('status', TrainerPlayerStatus::Active)
            ->pluck('trainerProfile')
            ->filter()
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, TrainerProfile> */
    public function availableTrainersFor(int $childId): Collection
    {
        $alreadyAssociated = $this->familyAssociations()
            ->where('player_profile_id', $childId)
            ->pluck('trainer_profile_id');

        return $this->familyTrainerProfiles()->whereNotIn('id', $alreadyAssociated)->values();
    }

    public function addByCode(int $childId, RedeemAction $redeem): void
    {
        $child = $this->authorizedChild($childId);
        $code = trim((string) ($this->manualCode[$childId] ?? ''));

        if ($code === '') {
            throw ValidationException::withMessages([
                "manualCode.{$childId}" => 'Enter an invitation code.',
            ]);
        }

        try {
            $associations = $redeem->forPlayer($code, $this->actor(), [$child->getKey()]);
        } catch (ShareLinkNotRedeemableException) {
            $associations = collect();
        }

        if ($associations->isEmpty()) {
            throw ValidationException::withMessages([
                "manualCode.{$childId}" => 'This invitation link is no longer valid. It may have been replaced, already used, or expired.',
            ]);
        }

        unset($this->manualCode[$childId]);

        session()->flash('status', $child->name.' joined the trainer.');
    }

    public function addTrainer(int $childId, AssociatePlayersWithTrainer $associate): void
    {
        $child = $this->authorizedChild($childId);
        $trainerProfileId = $this->pickerTrainerId[$childId] ?? null;

        abort_if($trainerProfileId === null, 422);

        // Re-derived here, not trusted from the picker's submitted value: only a trainer already
        // reachable by this family may be added this way (Decision 4).
        if (! $this->familyTrainerProfiles()->contains('id', (int) $trainerProfileId)) {
            abort(403);
        }

        $trainer = TrainerProfile::query()->findOrFail($trainerProfileId);

        $associate->handle($trainer, $this->actor(), [$child->getKey()]);

        unset($this->pickerTrainerId[$childId]);

        session()->flash('status', $child->name.' joined '.$trainer->business_name.'.');
    }

    public function remove(int $associationId, ManageChildTrainerAssociation $manage): void
    {
        $association = $this->familyAssociations()->firstWhere('id', $associationId);

        abort_if($association === null, 404);

        $this->authorize('delete', $association);

        $manage->remove($association, $this->actor());

        // Deliberately just "removed": FR-009's RSVP-cancellation warning belongs on the confirm
        // prompt before this runs (see the view), not as a claim of fact here — no RSVP model
        // exists yet for anything to actually cancel (ManageChildTrainerAssociation's docblock).
        session()->flash('status', 'Trainer removed.');
    }

    /**
     * A guarded child's associations are a guardian-only action (`manageTrainerAssociations`
     * already encodes that, including the child-account deny half). A user's own self profile has
     * no guardian pivot row at all (`GuardianshipTest` pins this), so that policy would always
     * refuse it — self-management needs only "you are this profile," the same authorization
     * `AssociatePlayersWithTrainer`'s add path already relies on via `trainableProfiles()`.
     *
     * 404, not 403, for a profile outside the family entirely: route-model binding through an
     * identity relation reveals nothing about a row the actor cannot even see (AD-009's reasoning).
     */
    protected function authorizedChild(int $childId): PlayerProfile
    {
        $actor = $this->actor();
        $child = $actor->trainableProfiles()->firstWhere('id', $childId);

        abort_if($child === null, 404);

        if ($child->user_id === $actor->getKey()) {
            abort_if($actor->is_child_account, 403);

            return $child;
        }

        $this->authorize('manageTrainerAssociations', $child);

        return $child;
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        $actor = $this->actor();
        $associations = $this->familyAssociations();

        $children = $actor->trainableProfiles()->map(fn (PlayerProfile $profile): array => [
            'profile' => $profile,
            'associations' => $associations->where('player_profile_id', $profile->getKey())->values(),
            'availableTrainers' => $this->availableTrainersFor($profile->getKey()),
        ]);

        return view('livewire.family.overview', [
            'children' => $children,
            'canManage' => ! $actor->is_child_account,
        ]);
    }
}
