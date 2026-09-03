<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CoachStatus;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\CoachProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

// Tenant-owned (AD-001): BelongsToTenant applies the fail-closed scope and fills
// `trainer_profile_id` on create, so a coach listing with no resolved organisation is empty rather
// than global.
// `user_id` and `trainer_profile_id` are not mass-assignable: a request-supplied
// trainer_profile_id would place a coach inside someone else's organisation, which is the leakage
// NFR-010 forbids. Create through the relationship instead.
/**
 * @property bool $is_public
 * @property CoachStatus $status
 */
// `status` and `joined_at` are out of the allow-list deliberately: they carry the BR-006 active
// slot, and a coach passes `update` on their own row. Leaving them fillable would mean any future
// `update($request->validated())` on that row is self-reactivation past a release. AcceptCoachInvitation
// and ReleaseCoach own them, and both already use forceFill.
#[Fillable([
    'bio',
    'credentials',
    'certifications',
    'is_public',
])]
class CoachProfile extends Model
{
    /** @use HasFactory<CoachProfileFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'joined_at' => 'datetime',
            'status' => CoachStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * FR-015's fixed (no default/override toggle) weekly schedule. Always `trainer_profile_id`
     * non-null — a coach has exactly one employer.
     *
     * @return MorphMany<Availability, $this>
     */
    public function availabilities(): MorphMany
    {
        return $this->morphMany(Availability::class, 'available_for');
    }

    /** This coach's own conflict-override history. */
    /** @return HasMany<CoachAvailabilityOverride, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(CoachAvailabilityOverride::class);
    }
}
