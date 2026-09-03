<?php

declare(strict_types=1);

namespace App\Actions\Availability;

use App\Models\CoachAvailabilityOverride;
use App\Models\CoachProfile;
use App\Services\AuditLogger;

/**
 * FR-015's "partially blocked" half. No caller in this slice — Epic-02's event-assignment flow is
 * the intended caller, exercised only by its own unit test and `CoachAvailabilityOverridePolicy`'s
 * own test until that flow exists.
 */
final class OverrideCoachAvailability
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(CoachProfile $coach, ?int $eventId, string $reason): CoachAvailabilityOverride
    {
        $override = new CoachAvailabilityOverride;

        // forceFill: none of these four columns is fillable (`#[Fillable(['reason'])]` only) — the
        // three ownership columns decide whose row this is (AD-016).
        $override->forceFill([
            'coach_profile_id' => $coach->getKey(),
            'trainer_profile_id' => $coach->trainer_profile_id,
            'event_id' => $eventId,
            'reason' => $reason,
        ]);
        $override->save();

        $this->auditLogger->log('coach-availability.overridden', $override, [
            'coach_profile_id' => $coach->getKey(),
            'event_id' => $eventId,
        ]);

        return $override;
    }
}
