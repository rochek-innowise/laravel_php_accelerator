<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\CoachAvailabilityOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Tenant-owned (AD-001), unlike `Availability` itself: this is a trainer's own record of a coach
// conflict override, not identity data. `coach_profile_id` and `trainer_profile_id` are not
// mass-assignable — the owning action sets both via forceFill (AD-016).
//
// `event_id` stays a plain nullable integer attribute, not a relation, until Epic-02's `Event`
// model exists (Slice D plan, Gap 4) — the column has no foreign key yet either.
/**
 * @property int $coach_profile_id
 * @property int $trainer_profile_id
 * @property int|null $event_id
 * @property string $reason
 */
#[Fillable(['reason'])]
class CoachAvailabilityOverride extends Model
{
    /** @use HasFactory<CoachAvailabilityOverrideFactory> */
    use BelongsToTenant, HasFactory;

    /** @return BelongsTo<CoachProfile, $this> */
    public function coachProfile(): BelongsTo
    {
        return $this->belongsTo(CoachProfile::class);
    }
}
