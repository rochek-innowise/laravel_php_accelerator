<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fail-closed tenancy (AD-001).
 *
 * With no resolved tenant this applies `0 = 1` rather than returning every row. That inverts the
 * failure mode: a missing context becomes an empty list somebody reports, never a silent
 * cross-tenant read. Never add an early `return` to the null branch.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $query, Model $model): void
    {
        $context = app(TrainerContext::class);

        if ($context->isSuppressed()) {
            return;
        }

        $tenantId = $context->id();

        if ($tenantId === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where($model->getTable().'.trainer_profile_id', $tenantId);
    }
}
