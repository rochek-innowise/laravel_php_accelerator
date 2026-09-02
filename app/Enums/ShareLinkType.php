<?php

declare(strict_types=1);

namespace App\Enums;

enum ShareLinkType: string
{
    /** BR-008: unlimited uses, no expiry. */
    case Player = 'player';

    /** BR-009: single use, 7-day expiry. */
    case Coach = 'coach';

    public function label(): string
    {
        return match ($this) {
            self::Player => 'Player invitation',
            self::Coach => 'Coach invitation',
        };
    }

    public function isSingleUse(): bool
    {
        return $this->maxUses() === 1;
    }

    public function maxUses(): ?int
    {
        return match ($this) {
            self::Player => null,
            self::Coach => 1,
        };
    }

    /** Days until the link stops being redeemable, or null for a permanent link. */
    public function ttlInDays(): ?int
    {
        return match ($this) {
            self::Player => null,
            self::Coach => 7,
        };
    }
}
