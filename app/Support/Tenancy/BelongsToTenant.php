<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Exceptions\TenantContextMissingException;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model (AD-001): `ShareLink`, `TrainerPlayer`, `CoachProfile`, and
 * later events, tokens and content. Identity models — `User`, `TrainerProfile`, `PlayerProfile`,
 * `AuditLog` — must never use it: a `PlayerProfile` is one person who exists once and is projected
 * into organisations through `TrainerPlayer`, so scoping it would break the family view.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('trainer_profile_id') !== null) {
                return;
            }

            $tenantId = app(TrainerContext::class)->id();

            if ($tenantId === null) {
                throw TenantContextMissingException::forModel($model::class);
            }

            $model->setAttribute('trainer_profile_id', $tenantId);
        });
    }

    /** @return BelongsTo<TrainerProfile, $this> */
    public function trainerProfile(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class);
    }

    /**
     * The admin escape hatch (AD-003), gated on Super Admin so it cannot become the normal read
     * path. System paths with no authenticated admin use `TrainerContext::runAsSystem()` instead.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     *
     * @throws AuthorizationException
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            throw new AuthorizationException('Reading across organisations requires a Super Admin.');
        }

        return $query->withoutGlobalScope(TenantScope::class);
    }
}
