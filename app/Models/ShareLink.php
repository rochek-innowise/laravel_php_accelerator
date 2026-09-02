<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShareLinkType;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ShareLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Tenant-owned (AD-001). `code`, `trainer_profile_id` and `created_by_user_id` are not
// mass-assignable: the code is minted, not supplied, and a request-supplied tenant id would let one
// trainer issue invitations into another's organisation.
/**
 * @property string $code
 * @property ShareLinkType $type
 * @property int $trainer_profile_id
 * @property string|null $target_email
 * @property Carbon|null $expires_at
 * @property int|null $max_uses
 * @property int $uses_count
 * @property bool $is_active
 */
#[Fillable([
    'type',
    'target_email',
    'expires_at',
    'max_uses',
])]
class ShareLink extends Model
{
    /** @use HasFactory<ShareLinkFactory> */
    use BelongsToTenant, HasFactory;

    /** 32 bytes of randomness. A player link never expires, so a guessable code would be a
     * permanent unauthorised route into a roster. */
    public const CODE_BYTES = 16;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ShareLinkType::class,
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public static function mintCode(): string
    {
        return Str::lower(bin2hex(random_bytes(self::CODE_BYTES)));
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->uses_count >= $this->max_uses;
    }

    public function isRedeemable(): bool
    {
        return $this->is_active && ! $this->isExpired() && ! $this->isExhausted();
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<TrainerPlayer, $this> */
    public function trainerPlayers(): HasMany
    {
        return $this->hasMany(TrainerPlayer::class);
    }
}
