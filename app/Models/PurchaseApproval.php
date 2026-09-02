<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentType;
use Database\Factories\PurchaseApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

// Owner-scoped, not tenant-owned (AD-001's third data class): reached only through the owning
// PlayerProfile, never queried from a trainer screen. No trainer_profile_id, no BelongsToTenant.
//
// Only `parent_note` is mass-assignable (AD-016): `status`, `player_profile_id`, `amount_cents`
// and `payment_type` decide the outcome of a purchase and who it belongs to — a request-supplied
// `status` would let a child approve their own purchase. Actions set the rest via forceFill.
/**
 * @property int $player_profile_id
 * @property ApprovalStatus $status
 * @property PaymentType $payment_type
 * @property int $amount_cents
 * @property Carbon $requested_at
 * @property Carbon|null $responded_at
 * @property Carbon $expires_at
 * @property string|null $parent_note
 */
#[Fillable(['parent_note'])]
class PurchaseApproval extends Model
{
    /** @use HasFactory<PurchaseApprovalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'payment_type' => PaymentType::class,
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlayerProfile, $this> */
    public function playerProfile(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class);
    }

    /** The purchasable subject, once Epic-02 has one to point at. Nullable until then. */
    /** @return MorphTo<Model, $this> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }
}
