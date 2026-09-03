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
use Illuminate\Support\Facades\Storage;

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

    /**
     * FR-019 / Gap 12: the logo lives on the public disk, unlike a profile photo — business
     * identity meant to render for every member of the organisation on every page load, so a
     * plain public URL is the right shape here, not a per-render signed one (AD-020 is deliberately
     * not applied to this column).
     */
    public function logoUrl(): ?string
    {
        if (empty($this->logo_path)) {
            return null;
        }

        return Storage::disk(config('media.trainer_logos.disk'))->url($this->logo_path);
    }
}
