<?php

declare(strict_types=1);

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Denied => 'Denied',
            self::Expired => 'Expired',
        };
    }

    /** Every case but Pending is a terminal state: the state machine ratified in brainstorming has no transition out of it. */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
