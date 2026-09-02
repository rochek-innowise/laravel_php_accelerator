<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ShareLink;
use RuntimeException;

/**
 * A link that is missing, deactivated, expired or already spent.
 *
 * One exception for all four, and deliberately so: telling a caller *which* of those a code is
 * would let someone probe a namespace of invitation codes for near-misses. The trainer's own
 * screen distinguishes them from the row it already holds.
 */
final class ShareLinkNotRedeemableException extends RuntimeException
{
    public function __construct(public readonly ?ShareLink $link = null)
    {
        parent::__construct('This invitation link is no longer valid.');
    }
}
