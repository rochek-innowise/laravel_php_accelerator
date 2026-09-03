<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;

/**
 * Order: tenant membership -> role -> child deny list (AD-005). Tenancy is not yet a factor for
 * identity records; Slice B adds it where a trainer reaches a user through an association.
 * The child deny list is enforced globally by a Gate::before hook, so it is not repeated here.
 * Super Admin short-circuits in Gate::before; the explicit checks below keep each method honest
 * on its own.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, User $subject): bool
    {
        return $user->is($subject) || $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, User $subject): bool
    {
        return $user->is($subject) || $user->isSuperAdmin();
    }

    public function deactivate(User $user, User $subject): bool
    {
        return $user->isSuperAdmin() && ! $user->is($subject);
    }

    public function delete(User $user, User $subject): bool
    {
        return $user->isSuperAdmin() && ! $user->is($subject);
    }

    public function impersonate(User $user, User $subject): bool
    {
        // BR-016: never another Super Admin, never a non-active account, and never a second
        // impersonation stacked on top of one already running (Slice D Gap 1).
        return $user->isSuperAdmin()
            && ! $subject->isSuperAdmin()
            && $subject->status === UserStatus::Active
            && ! $this->impersonationAlreadyActive();
    }

    public function reactivate(User $user, User $subject): bool
    {
        return $user->isSuperAdmin() && ! $user->is($subject);
    }

    protected function impersonationAlreadyActive(): bool
    {
        $request = request();

        return $request->hasSession() && $request->session()->has('impersonator_id');
    }
}
