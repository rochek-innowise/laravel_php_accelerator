<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlayerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Identity, never tenant-scoped (AD-001): a trainer's roster is a query over trainer_players.
// `owner_user_id` and `user_id` are not mass-assignable: a request-supplied owner would let one
// account claim another family's child. Create through $user->ownedPlayerProfiles().
/**
 * @property bool $is_child
 * @property bool $token_spend_requires_approval
 */
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

    /** The account that owns this person — the parent, or the player themselves. */
    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** The optional login backing this profile. */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
