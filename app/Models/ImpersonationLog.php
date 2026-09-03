<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ImpersonationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

// No #[Fillable] at all (mirrors AuditLog) — written only through StartImpersonation,
// StopImpersonation and CloseStaleImpersonationLogsJob, all via forceFill. Identity table
// (AD-001) — no BelongsToTenant.
/**
 * @property int|null $admin_user_id
 * @property int|null $target_user_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 * @property string|null $ip_address
 */
class ImpersonationLog extends Model
{
    /** @use HasFactory<ImpersonationLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
