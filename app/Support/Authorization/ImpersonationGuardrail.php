<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\User;

/**
 * The signed-off deviation from "permissions match the target exactly" (brainstorming Decision 6,
 * gap G-12): while impersonating, a Super Admin must not be able to take over the account
 * permanently or spend the target's money. Mirrors ChildAbilities's exact shape — one array, one
 * static check, one test that iterates it.
 */
final class ImpersonationGuardrail
{
    /** @var list<string> */
    public const DENIED = [
        'user.change-credentials',
        'payment-method.create',
        'payment-method.delete',
        'tokens.purchase',
        'purchase.complete',
    ];

    public static function denies(string $ability, mixed $subject = null): bool
    {
        if (in_array($ability, self::DENIED, true)) {
            return true;
        }

        // `delete` is shared with ShareLinkPolicy/TrainerPlayerPolicy; scoping by subject type
        // keeps those unaffected while impersonating — only deleting the *account itself* is
        // denied, matching Decision 6's "deleting the account" (not "deleting anything").
        return $ability === 'delete' && $subject instanceof User;
    }
}
