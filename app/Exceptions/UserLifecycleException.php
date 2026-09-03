<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * BR-018: a Deleted (GDPR-anonymized) account is a terminal state. Deactivate, reactivate and
 * delete all refuse a target already in it — reactivation of a deleted account must be impossible,
 * and re-deactivating or re-anonymizing an already-erased identity has nothing left to do and
 * would only relitigate a decision that already happened.
 */
final class UserLifecycleException extends RuntimeException
{
    public static function alreadyDeleted(User $target): self
    {
        return new self("User {$target->getKey()} has already been deleted and cannot be modified further.");
    }
}
