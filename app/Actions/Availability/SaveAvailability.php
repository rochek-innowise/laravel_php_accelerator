<?php

declare(strict_types=1);

namespace App\Actions\Availability;

use App\Enums\DayOfWeek;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * FR-014/FR-015. Decision 3: the write side of "an override wholly replaces the default" —
 * delete-then-replace, never a row-level patch.
 */
final class SaveAvailability
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Passing an empty `$ranges` with a non-null `$trainerProfileId` is exactly "Reset to
     * default": it deletes the override rows and `AvailabilityResolver` falls back to the default
     * set on the very next read.
     *
     * @param  list<array{day_of_week: DayOfWeek|int, start_time: ?string, end_time: ?string, is_available: bool}>  $ranges
     */
    public function handle(PlayerProfile|CoachProfile $subject, ?int $trainerProfileId, array $ranges): void
    {
        DB::transaction(function () use ($subject, $trainerProfileId, $ranges): void {
            // A `where('trainer_profile_id', $trainerProfileId)` call correctly compiles to
            // `IS NULL` when the value is null (the query builder special-cases it), so this one
            // line deletes exactly the set being replaced, default or override alike.
            $subject->availabilities()
                ->where('trainer_profile_id', $trainerProfileId)
                ->delete();

            foreach ($ranges as $range) {
                // `make()` sets the morph columns from the relation; the four range fields are
                // mass-assigned through the model's own allow-list. `trainer_profile_id` is not
                // fillable (AD-016 — it decides whose set this is), so it is forced separately.
                $availability = $subject->availabilities()->make($range);
                $availability->forceFill(['trainer_profile_id' => $trainerProfileId]);
                $availability->save();
            }

            $this->auditLogger->log('availability.saved', $subject, [
                'trainer_profile_id' => $trainerProfileId,
                'day_count' => count($ranges),
            ]);
        });
    }
}
