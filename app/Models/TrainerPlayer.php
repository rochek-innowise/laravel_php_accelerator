<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TrainerPlayerStatus;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\TrainerPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Tenant-owned (AD-001), and the row that decides reachability: a person is inside an organisation
// because this row exists, not because of any column on the person.
//
// Nothing here is mass-assignable except `status`. `trainer_profile_id`, `player_profile_id` and
// `share_link_id` all decide *whose* data this is, which is precisely the NFR-010 breach a stray
// update($request->validated()) would open. The actions set them through the relationship.
/**
 * @property TrainerPlayerStatus $status
 */
#[Fillable(['status'])]
class TrainerPlayer extends Model
{
    /** @use HasFactory<TrainerPlayerFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TrainerPlayerStatus::class,
            'connected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlayerProfile, $this> */
    public function playerProfile(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class);
    }

    /** @return BelongsTo<ShareLink, $this> */
    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class);
    }

    public function isActive(): bool
    {
        return $this->status === TrainerPlayerStatus::Active;
    }
}
