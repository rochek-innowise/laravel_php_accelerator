<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\PlayerProfile;
use RuntimeException;

/**
 * A profile-only child has no login to act with, so there is nobody who could have initiated a
 * purchase — this throws rather than silently creating a request nobody can be shown to have made.
 */
final class PurchaseApprovalException extends RuntimeException
{
    public static function forProfileOnlyChild(PlayerProfile $child): self
    {
        return new self("Player profile {$child->getKey()} has no login and cannot request a purchase approval.");
    }
}
