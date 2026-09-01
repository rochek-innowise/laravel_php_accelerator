<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CoachProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Tenant-owned (AD-001). TODO(coder): apply BelongsToTenant in Slice B.
// `user_id` and `trainer_profile_id` are not mass-assignable: a request-supplied
// trainer_profile_id would place a coach inside someone else's organisation, which is the leakage
// NFR-010 forbids. Create through the relationship instead.
/**
 * @property bool $is_public
 */
#[Fillable([
    'status',
    'bio',
    'credentials',
    'certifications',
    'is_public',
    'joined_at',
])]
class CoachProfile extends Model
{
    /** @use HasFactory<CoachProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<TrainerProfile, $this> */
    public function trainerProfile(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class);
    }
}
