<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentType: string
{
    case Usd = 'usd';
    case Token = 'token';

    public function label(): string
    {
        return match ($this) {
            self::Usd => 'USD',
            self::Token => 'Token',
        };
    }
}
