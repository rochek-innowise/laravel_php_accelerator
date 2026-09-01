<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deleted = 'deleted';

    public function canLogIn(): bool
    {
        return $this === self::Active;
    }
}
