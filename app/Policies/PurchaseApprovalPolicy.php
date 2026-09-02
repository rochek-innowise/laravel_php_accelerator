<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PurchaseApproval;
use App\Models\User;

/**
 * `PurchaseApproval` is owner-scoped, not tenant-owned (AD-001's third data class) — there is no
 * tenant membership step here, only the guardian/child ownership `PlayerProfilePolicy` already
 * expresses.
 */
final class PurchaseApprovalPolicy
{
    /** The same ownsOrIs shape PlayerProfilePolicy uses: a guardian, or the child's own login. */
    public function view(User $user, PurchaseApproval $approval): bool
    {
        return $approval->playerProfile->isGuardedBy($user)
            || $user->id === $approval->playerProfile->user_id;
    }

    /** FR-010/FR-011: a guardian only, never the child, and only while the row is still pending. */
    public function respond(User $user, PurchaseApproval $approval): bool
    {
        if ($user->is_child_account) {
            return false;
        }

        return $approval->playerProfile->isGuardedBy($user) && $approval->isPending();
    }
}
