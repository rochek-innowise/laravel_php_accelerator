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
    /**
     * `payment-method.create`, `payment-method.delete`, `tokens.purchase` and `purchase.complete`
     * are forward-looking strings for Epic-05 — nothing in the app authorizes them yet.
     * `respond` (PurchaseApprovalPolicy) is the financial-consent ability that exists *today*:
     * approving a child's purchase forges the guardian's consent, and only fails to spend money
     * because ApprovedPurchaseExecutor is currently bound to NullPurchaseExecutor (AD-006) — it
     * becomes a real charge the day Epic-05 rebinds it. `respond` is unique to
     * PurchaseApprovalPolicy across every policy in the app, so no subject-type scoping is needed.
     *
     * @var list<string>
     */
    public const DENIED = [
        'user.change-credentials',
        'payment-method.create',
        'payment-method.delete',
        'tokens.purchase',
        'purchase.complete',
        'respond',
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
