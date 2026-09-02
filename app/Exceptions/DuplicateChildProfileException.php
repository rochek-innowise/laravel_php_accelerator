<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Decision 2 in the Slice C plan: a dismissible warning, never a hard block. The caller re-submits
 * with `confirmDuplicate: true` on `ChildProfileData` to proceed past it.
 */
final class DuplicateChildProfileException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self("A child named {$name} with a matching birth year already exists in your family.");
    }
}
