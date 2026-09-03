<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Enums\DayOfWeek;
use App\Enums\TrainerPlayerStatus;
use App\Models\Availability;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The single read path for availability data (Slice D plan: "All reads go through
 * AvailabilityResolver, not raw joins scattered across callers"). Decision 3's rule lives in one
 * place: a per-trainer override wholly replaces the default set — never a row-level merge.
 */
final class AvailabilityResolver
{
    /**
     * If `$trainerProfileId` is given and an override set exists for it, returns that set wholly;
     * otherwise returns the default (`trainer_profile_id IS NULL`) set.
     *
     * @return Collection<int, Availability>
     */
    public function resolve(Model $subject, ?int $trainerProfileId): Collection
    {
        if ($trainerProfileId !== null) {
            $override = $this->query($subject, $trainerProfileId)->get();

            if ($override->isNotEmpty()) {
                return $override;
            }
        }

        return $this->query($subject, null)->get();
    }

    /**
     * For the Grid's "Using my default times" / "Custom for Trainer B" label and its Reset
     * control: true when no override rows exist for this trainer, so the default set is the one
     * actually in effect.
     */
    public function isUsingDefault(Model $subject, ?int $trainerProfileId): bool
    {
        if ($trainerProfileId === null) {
            return true;
        }

        return ! $this->query($subject, $trainerProfileId)->exists();
    }

    /**
     * FR-014's CRM filter (Gap 5): every player on this trainer's active roster who is free for
     * the given day/window, judged by their override for this trainer if one exists, by their
     * default otherwise — because an override wholly replaces the default, a player with both must
     * be judged by the override alone, never by either matching.
     *
     * Joined from `trainer_players`, never `PlayerProfile::query()` directly (AD-001/AD-009):
     * tenancy is enforced twice over here — the explicit `trainer_profile_id` filter below, and
     * `TrainerPlayer`'s own fail-closed `TenantScope`. Each correlated `EXISTS` is an index lookup
     * against `(available_for_type, available_for_id, trainer_profile_id)` plus the day/time
     * predicate against `(trainer_profile_id, day_of_week, start_time)` — the two indexes Decision
     * 3 names. A single trainer's own roster is orders of magnitude below NFR-002's 10,000-row
     * directory scale, so these existing indexes are sufficient without further work.
     *
     * @return Builder<PlayerProfile>
     */
    public function rosterAvailableAt(TrainerProfile $trainer, DayOfWeek $day, string $start, string $end): Builder
    {
        $rosterPlayerIds = TrainerPlayer::query()
            ->where('trainer_profile_id', $trainer->getKey())
            ->where('status', TrainerPlayerStatus::Active)
            ->select('player_profile_id');

        return PlayerProfile::query()
            ->whereIn('id', $rosterPlayerIds)
            ->where(function (Builder $query) use ($trainer, $day, $start, $end): void {
                $query->whereExists(
                    fn (QueryBuilder $q): QueryBuilder => $this->overrideCoversWindow($q, $trainer, $day, $start, $end)
                )->orWhere(function (Builder $query) use ($trainer, $day, $start, $end): void {
                    $query->whereNotExists(
                        fn (QueryBuilder $q): QueryBuilder => $this->hasAnyOverrideFor($q, $trainer)
                    )->whereExists(
                        fn (QueryBuilder $q): QueryBuilder => $this->defaultCoversWindow($q, $day, $start, $end)
                    );
                });
            });
    }

    /** @return Builder<Availability> */
    protected function query(Model $subject, ?int $trainerProfileId): Builder
    {
        return Availability::query()
            ->where('available_for_type', $subject::class)
            ->where('available_for_id', $subject->getKey())
            // Compiles to `IS NULL` when $trainerProfileId is null — the query builder special
            // cases it, so the default-set lookup needs no separate whereNull branch.
            ->where('trainer_profile_id', $trainerProfileId);
    }

    protected function overrideCoversWindow(
        QueryBuilder $query,
        TrainerProfile $trainer,
        DayOfWeek $day,
        string $start,
        string $end,
    ): QueryBuilder {
        return $this->correlatedToOuterPlayer($query)
            ->where('availabilities.trainer_profile_id', $trainer->getKey())
            ->where('availabilities.day_of_week', $day->value)
            ->where('availabilities.is_available', true)
            ->where('availabilities.start_time', '<=', $start)
            ->where('availabilities.end_time', '>=', $end);
    }

    /** Any override row at all for this trainer — an override replaces the whole default set. */
    protected function hasAnyOverrideFor(QueryBuilder $query, TrainerProfile $trainer): QueryBuilder
    {
        return $this->correlatedToOuterPlayer($query)
            ->where('availabilities.trainer_profile_id', $trainer->getKey());
    }

    protected function defaultCoversWindow(QueryBuilder $query, DayOfWeek $day, string $start, string $end): QueryBuilder
    {
        return $this->correlatedToOuterPlayer($query)
            ->whereNull('availabilities.trainer_profile_id')
            ->where('availabilities.day_of_week', $day->value)
            ->where('availabilities.is_available', true)
            ->where('availabilities.start_time', '<=', $start)
            ->where('availabilities.end_time', '>=', $end);
    }

    /** The correlation every sub-query above shares: this row belongs to the outer player. */
    protected function correlatedToOuterPlayer(QueryBuilder $query): QueryBuilder
    {
        return $query->selectRaw('1')
            ->from('availabilities')
            ->where('availabilities.available_for_type', PlayerProfile::class)
            ->whereColumn('availabilities.available_for_id', 'player_profiles.id');
    }
}
