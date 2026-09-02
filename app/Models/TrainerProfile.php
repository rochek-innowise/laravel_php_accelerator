<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrainerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /** @return HasMany<ShareLink, $this> */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(ShareLink::class);
    }

    /** The roster. Reachability inside this organisation is this row, never a column on the
     * person (AD-001).
     *
     * @return HasMany<TrainerPlayer, $this>
     */
    public function trainerPlayers(): HasMany
    {
        return $this->hasMany(TrainerPlayer::class);
    }

    /** @return BelongsToMany<PlayerProfile, $this> */
    public function playerProfiles(): BelongsToMany
    {
        return $this->belongsToMany(PlayerProfile::class, 'trainer_players')
            ->withPivot(['status', 'connected_at'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }
}
