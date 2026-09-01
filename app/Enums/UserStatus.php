<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deleted = 'deleted';

    /** FR-017 copy, shared by the login pipeline and the per-request guard. */
    public const DEACTIVATED_MESSAGE = 'Account deactivated. Contact support.';

    public function canLogIn(): bool
    {
        return $this === self::Active;
    }
}
