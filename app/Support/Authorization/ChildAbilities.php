<?php

declare(strict_types=1);

namespace App\Support\Authorization;

/**
 * The one place FR-011's child deny list lives. A Gate::before hook refuses every entry for a
 * child account, and a single test iterates this array — adding an ability without a test is
 * therefore impossible.
 */
final class ChildAbilities
{
    /** @var list<string> */
    public const DENIED = [
        'trainer.associate',
        'payment-method.create',
        'payment-method.delete',
        'tokens.purchase',
        'purchase.complete',
        'account.delete',
        'trainer-association.change',
        'parent-data.view',
    ];

    public static function denies(string $ability): bool
    {
        return in_array($ability, self::DENIED, true);
    }
}
