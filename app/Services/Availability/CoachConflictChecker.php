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
     *
     * Gap 6: the grid lets a coach enter adjacent ranges (`09:00-11:00` and `11:00-13:00`) as two
     * separate rows, and a window flush against the seam between them (`10:00-12:00`) is
     * genuinely, continuously available — no single row contains it, so the naive per-row
     * containment test below would misreport a conflict. Merging contiguous/overlapping ranges
     * first makes the containment test correct regardless of how the grid happened to split them.
     */
    public function hasConflict(CoachProfile $coach, DayOfWeek $day, string $startTime, string $endTime): bool
    {
        $ranges = $this->resolver->resolve($coach, $coach->trainer_profile_id)
            ->where('day_of_week', $day)
            ->where('is_available', true)
            ->filter(fn ($range): bool => $range->start_time !== null && $range->end_time !== null);

        foreach ($this->mergeContiguous($ranges) as [$start, $end]) {
            if ($start <= $startTime && $end >= $endTime) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  iterable<object{start_time: string, end_time: string}>  $ranges
     * @return list<array{0: string, 1: string}>
     */
    private function mergeContiguous(iterable $ranges): array
    {
        $sorted = collect($ranges)
            ->sortBy(fn ($range): string => $range->start_time)
            ->values();

        $merged = [];

        foreach ($sorted as $range) {
            $last = count($merged) - 1;

            // Overlapping or exactly adjacent (this range starts no later than the previous one
            // ends) extends the current run rather than starting a new one — that is what makes
            // 09:00-11:00 + 11:00-13:00 read as one continuous 09:00-13:00 span.
            if ($last >= 0 && $range->start_time <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range->end_time);

                continue;
            }

            $merged[] = [$range->start_time, $range->end_time];
        }

        return $merged;
    }
}
