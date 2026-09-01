<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrainerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// `user_id` is not mass-assignable: it is the tenant root, so create through the relationship
// ($user->trainerProfile()->create(...)) and let Eloquent set the owner.
#[Fillable([
    'business_name',
    'slug',
    'address',
    'website',
    'description',
    'logo_path',
    'primary_color',
])]
class TrainerProfile extends Model
{
    /** @use HasFactory<TrainerProfileFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CoachProfile, $this> */
    public function coachProfiles(): HasMany
    {
        return $this->hasMany(CoachProfile::class);
    }
}
