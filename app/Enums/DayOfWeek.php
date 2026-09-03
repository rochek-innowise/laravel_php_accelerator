<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Carbon's own convention (`now()->dayOfWeek`): 0 = Sunday ... 6 = Saturday. Backed as int so a
 * value read straight off Carbon needs no translation table before it matches a case here.
 */
enum DayOfWeek: int
{
    case Sunday = 0;
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;

    public function label(): string
    {
        return match ($this) {
            self::Sunday => 'Sunday',
            self::Monday => 'Monday',
            self::Tuesday => 'Tuesday',
            self::Wednesday => 'Wednesday',
            self::Thursday => 'Thursday',
            self::Friday => 'Friday',
            self::Saturday => 'Saturday',
        };
    }

    /** "Best Times: Mon 5-8pm" summary copy (FR-014). */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Sunday => 'Sun',
            self::Monday => 'Mon',
            self::Tuesday => 'Tue',
            self::Wednesday => 'Wed',
            self::Thursday => 'Thu',
            self::Friday => 'Fri',
            self::Saturday => 'Sat',
        };
    }
}
