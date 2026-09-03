<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use App\Actions\Availability\OverrideCoachAvailability;
use App\Models\AuditLog;
use App\Models\CoachProfile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-015's "partially blocked" half. No caller in this slice — Epic-02's event-assignment flow is
 * the intended caller (Gap 4); this test exercises the action directly.
 */
final class OverrideCoachAvailabilityTest extends TestCase
{
    #[Test]
    public function it_writes_the_row_with_a_null_event_id_and_audits_it(): void
    {
        $coach = CoachProfile::factory()->create();

        $override = app(OverrideCoachAvailability::class)->handle($coach, null, 'Double-booked with another club.');

        $this->assertDatabaseHas('coach_availability_overrides', [
            'id' => $override->id,
            'coach_profile_id' => $coach->id,
            'trainer_profile_id' => $coach->trainer_profile_id,
            'event_id' => null,
            'reason' => 'Double-booked with another club.',
        ]);

        $log = AuditLog::where('action', 'coach-availability.overridden')->first();
        $this->assertNotNull($log);
        $this->assertSame($override->getKey(), $log->subject_id);
        $this->assertSame($coach->getKey(), $log->metadata['coach_profile_id']);
        $this->assertNull($log->metadata['event_id']);
    }
}
