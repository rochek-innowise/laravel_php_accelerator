<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlayerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    /** The optional login backing this profile. */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
