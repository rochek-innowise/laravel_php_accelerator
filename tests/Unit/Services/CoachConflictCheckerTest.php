<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\DayOfWeek;
use App\Models\Availability;
use App\Models\CoachProfile;
use App\Services\Availability\CoachConflictChecker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-015's conflict matrix, exercised as a unit test — no HTTP involved. A coach's set is always
 * resolved against their own `trainer_profile_id` (always non-null).
 */
final class CoachConflictCheckerTest extends TestCase
{
    protected CoachProfile $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coach = CoachProfile::factory()->create();
        Availability::factory()->coach($this->coach)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
            'is_available' => true,
        ]);
    }

    #[Test]
    public function a_window_fully_inside_the_available_range_has_no_conflict(): void
    {
        $this->assertFalse($this->checker()->hasConflict($this->coach, DayOfWeek::Monday, '18:00:00', '19:00:00'));
    }

    #[Test]
    public function a_window_matching_the_range_exactly_has_no_conflict(): void
    {
        $this->assertFalse($this->checker()->hasConflict($this->coach, DayOfWeek::Monday, '17:00:00', '20:00:00'));
    }

    #[Test]
    public function a_window_entirely_outside_the_available_range_conflicts(): void
    {
        $this->assertTrue($this->checker()->hasConflict($this->coach, DayOfWeek::Monday, '08:00:00', '09:00:00'));
    }

    #[Test]
    public function a_window_spanning_past_both_edges_of_the_range_conflicts(): void
    {
        $this->assertTrue($this->checker()->hasConflict($this->coach, DayOfWeek::Monday, '16:00:00', '21:00:00'));
    }

    #[Test]
    public function a_window_starting_before_the_range_but_ending_inside_it_conflicts(): void
    {
        $this->assertTrue($this->checker()->hasConflict($this->coach, DayOfWeek::Monday, '16:30:00', '19:00:00'));
    }

    #[Test]
    public function a_window_starting_inside_the_range_but_ending_after_it_conflicts(): void
    {
        $this->assertTrue($this->checker()->hasConflict($this->coach, DayOfWeek::Monday, '18:00:00', '20:30:00'));
    }

    #[Test]
    public function a_window_immediately_adjacent_to_the_range_but_not_overlapping_conflicts(): void
    {
        $this->assertTrue($this->checker()->hasConflict($this->coach, DayOfWeek::Monday, '20:00:01', '21:00:00'));
    }

    #[Test]
    public function a_different_day_with_no_available_range_conflicts(): void
    {
        $this->assertTrue($this->checker()->hasConflict($this->coach, DayOfWeek::Tuesday, '17:00:00', '18:00:00'));
    }

    #[Test]
    public function an_explicit_not_available_day_conflicts_even_within_the_usual_hours(): void
    {
        $coach = CoachProfile::factory()->create();
        Availability::factory()->coach($coach)->unavailable()->create([
            'day_of_week' => DayOfWeek::Wednesday,
        ]);

        $this->assertTrue($this->checker()->hasConflict($coach, DayOfWeek::Wednesday, '17:00:00', '18:00:00'));
    }

    /**
     * Gap 6, the finding itself: two adjacent available ranges — which the grid happily lets a
     * coach enter as two separate rows — must read as one continuous span. A window flush against
     * the seam between them must not be reported as a conflict.
     */
    #[Test]
    public function a_window_spanning_the_seam_between_two_adjacent_ranges_has_no_conflict(): void
    {
        $coach = CoachProfile::factory()->create();
        Availability::factory()->coach($coach)->create([
            'day_of_week' => DayOfWeek::Thursday,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'is_available' => true,
        ]);
        Availability::factory()->coach($coach)->create([
            'day_of_week' => DayOfWeek::Thursday,
            'start_time' => '11:00:00',
            'end_time' => '13:00:00',
            'is_available' => true,
        ]);

        $this->assertFalse($this->checker()->hasConflict($coach, DayOfWeek::Thursday, '10:00:00', '12:00:00'));
    }

    /** The same seam, but with the two rows overlapping by a minute rather than exactly touching. */
    #[Test]
    public function a_window_spanning_two_overlapping_ranges_has_no_conflict(): void
    {
        $coach = CoachProfile::factory()->create();
        Availability::factory()->coach($coach)->create([
            'day_of_week' => DayOfWeek::Friday,
            'start_time' => '09:00:00',
            'end_time' => '11:01:00',
            'is_available' => true,
        ]);
        Availability::factory()->coach($coach)->create([
            'day_of_week' => DayOfWeek::Friday,
            'start_time' => '11:00:00',
            'end_time' => '13:00:00',
            'is_available' => true,
        ]);

        $this->assertFalse($this->checker()->hasConflict($coach, DayOfWeek::Friday, '10:00:00', '12:00:00'));
    }

    /** Two ranges that do *not* touch must not be merged — the gap between them still conflicts. */
    #[Test]
    public function a_window_spanning_a_genuine_gap_between_two_ranges_still_conflicts(): void
    {
        $coach = CoachProfile::factory()->create();
        Availability::factory()->coach($coach)->create([
            'day_of_week' => DayOfWeek::Saturday,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'is_available' => true,
        ]);
        Availability::factory()->coach($coach)->create([
            'day_of_week' => DayOfWeek::Saturday,
            'start_time' => '11:30:00',
            'end_time' => '13:00:00',
            'is_available' => true,
        ]);

        $this->assertTrue($this->checker()->hasConflict($coach, DayOfWeek::Saturday, '10:00:00', '12:00:00'));
        // Each range still works on its own.
        $this->assertFalse($this->checker()->hasConflict($coach, DayOfWeek::Saturday, '09:30:00', '10:30:00'));
        $this->assertFalse($this->checker()->hasConflict($coach, DayOfWeek::Saturday, '11:45:00', '12:30:00'));
    }

    /** Multiple ranges out of chronological order must still merge correctly. */
    #[Test]
    public function ranges_seeded_out_of_order_still_merge_correctly(): void
    {
        $coach = CoachProfile::factory()->create();
        Availability::factory()->coach($coach)->create([
            'day_of_week' => DayOfWeek::Sunday,
            'start_time' => '15:00:00',
            'end_time' => '17:00:00',
            'is_available' => true,
        ]);
        Availability::factory()->coach($coach)->create([
            'day_of_week' => DayOfWeek::Sunday,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'is_available' => true,
        ]);
        Availability::factory()->coach($coach)->create([
            'day_of_week' => DayOfWeek::Sunday,
            'start_time' => '11:00:00',
            'end_time' => '15:00:00',
            'is_available' => true,
        ]);

        // 09:00-11:00, 11:00-15:00 and 15:00-17:00 merge into one continuous 09:00-17:00 span.
        $this->assertFalse($this->checker()->hasConflict($coach, DayOfWeek::Sunday, '10:00:00', '16:00:00'));
        $this->assertTrue($this->checker()->hasConflict($coach, DayOfWeek::Sunday, '08:00:00', '09:00:00'));
    }

    protected function checker(): CoachConflictChecker
    {
        return app(CoachConflictChecker::class);
    }
}
