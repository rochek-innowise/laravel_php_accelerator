<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Enums\DayOfWeek;
use App\Models\CoachProfile;

/**
 * FR-015's "partially blocked" half. No caller in this slice — Epic-02's event-assignment flow is
 * the intended caller, and this is the stated seam (Gap 4 / the objective's FR-015 boundary).
 */
final class CoachConflictChecker
{
    public function __construct(private readonly AvailabilityResolver $resolver) {}

    /**
     * False only if some available range in the coach's own set fully contains
     * `[$startTime, $endTime]`; true (a conflict) otherwise. A coach's set is always resolved
     * against their own `trainer_profile_id` — always non-null, since a coach has exactly one
     * employer.
     */
    public function hasConflict(CoachProfile $coach, DayOfWeek $day, string $startTime, string $endTime): bool
    {
        $ranges = $this->resolver->resolve($coach, $coach->trainer_profile_id)
            ->where('day_of_week', $day)
            ->where('is_available', true);

        foreach ($ranges as $range) {
            if ($range->start_time !== null && $range->end_time !== null
                && $range->start_time <= $startTime && $range->end_time >= $endTime) {
                return false;
            }
        }

        return true;
    }
}
