<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// No Fillable attribute on purpose: an audit row is written only by AuditLogger, and a
// mass-assignable actor_user_id would make the trail forgeable. Eloquent guards everything by
// default, so any write here has to be deliberate.
/**
 * @property array<string, mixed>|null $metadata
 */
class AuditLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** The identity the data attributes the write to. */
    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** The Super Admin behind an impersonated write, if any. */
    /** @return BelongsTo<User, $this> */
    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of_user_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
