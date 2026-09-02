<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\TenantScope;
use Database\Factories\PlayerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Identity, never tenant-scoped (AD-001): a trainer's roster is a query over trainer_players.
// `user_id` is not mass-assignable: a request-supplied login would let one account claim
// another family's child. Attach guardians through the guardians() relation.
/**
 * @property bool $is_child
 * @property bool $token_spend_requires_approval
 */
// `owner_user_id` is gone: guardianship lives in player_guardians so a child can have both
// parents. A self profile has no guardian row — it is reached through `user_id`.
#[Fillable([
    'name',
    'birth_date',
    'gender',
    'skill_level',
    'school',
    'jersey_number',
    'is_child',
    'emergency_contact',
    'token_spend_requires_approval',
])]
class PlayerProfile extends Model
{
    /** @use HasFactory<PlayerProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'is_child' => 'boolean',
            'token_spend_requires_approval' => 'boolean',
        ];
    }

    /** The accounts responsible for this person; empty for a self profile. */
    /** @return BelongsToMany<User, $this> */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'player_guardians', 'player_profile_id', 'guardian_user_id')
            ->withPivot(['relationship', 'is_primary'])
            ->withTimestamps();
    }

    public function isGuardedBy(User $user): bool
    {
        return $this->guardians()->whereKey($user->getKey())->exists();
    }

    /**
     * The organisations this person trains with.
     *
     * Tenant-blind, and the reasoning matters: this is keyed on the profile's own id, so it reads
     * one person's own memberships — the data behind the family view and the trainer switcher,
     * which are cross-organisation by definition. A *trainer's roster* is the opposite direction
     * and starts from `TrainerPlayer::query()`, which stays scoped.
     *
     * @return HasMany<TrainerPlayer, $this>
     */
    public function trainerAssociations(): HasMany
    {
        return $this->hasMany(TrainerPlayer::class)->withoutGlobalScope(TenantScope::class);
    }

    /** @return BelongsToMany<TrainerProfile, $this> */
    public function trainers(): BelongsToMany
    {
        return $this->belongsToMany(TrainerProfile::class, 'trainer_players')
            ->withPivot(['status', 'connected_at', 'deleted_at'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    /** The optional login backing this profile. */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** This person's purchase-approval history — requested, resolved, and expired alike. */
    /** @return HasMany<PurchaseApproval, $this> */
    public function purchaseApprovals(): HasMany
    {
        return $this->hasMany(PurchaseApproval::class);
    }
}
