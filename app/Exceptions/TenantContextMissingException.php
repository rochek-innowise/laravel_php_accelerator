<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A tenant-owned write attempted with no resolved tenant.
 *
 * Reads fail closed (an empty list somebody reports); writes fail loudly, because a row with no
 * owner is not a degraded result — it is corruption that no later query can attribute (AD-001).
 */
final class TenantContextMissingException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(
            "Cannot create a {$model} with no resolved trainer context. Wrap the work in "
            .'TrainerContext::runFor() or set trainer_profile_id explicitly.'
        );
    }
}
