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

    protected function checker(): CoachConflictChecker
    {
        return app(CoachConflictChecker::class);
    }
}
