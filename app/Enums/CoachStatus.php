<?php

declare(strict_types=1);

namespace App\Enums;

enum CoachStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Pending',
            self::Active => 'Active',
            self::Inactive => 'Released',
        };
    }

    /**
     * Only an active row occupies the BR-006 slot. The generated column mirrors this exactly —
     * `active_user_id = IF(status = 'active', user_id, NULL)` — so any change here without the
     * matching migration silently relaxes a database constraint.
     */
    public function occupiesTheActiveSlot(): bool
    {
        return $this === self::Active;
    }
}
