<?php

declare(strict_types=1);

namespace App\Actions\Trainer;

use App\Enums\CoachStatus;
use App\Models\CoachProfile;
use App\Services\AuditLogger;

/**
 * G-11: transfer between organisations is an explicit release, never an implicit steal.
 *
 * Setting the row inactive frees the BR-006 slot — the generated column becomes NULL — so the next
 * organisation's invitation can be accepted. The row itself stays: employment history is exactly
 * what FR-009's "history preserved" means.
 */
final class ReleaseCoach
{
    public function __construct(protected AuditLogger $auditLogger) {}

    public function handle(CoachProfile $profile): CoachProfile
    {
        $profile->forceFill(['status' => CoachStatus::Inactive])->save();

        $this->auditLogger->log('coach.released', $profile, [
            'trainer_profile_id' => $profile->trainer_profile_id,
            'user_id' => $profile->user_id,
        ]);

        return $profile;
    }
}
