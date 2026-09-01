<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Every method answers in this order: tenant membership -> role -> child deny list (AD-005).
 * The tenancy branch is a deliberate no-op until Slice B introduces TrainerContext, so Slice B
 * fills it in rather than inserting it — the retrofit that would otherwise open an NFR-010 gap.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        // TODO(coder): Super Admin only — the global directory (FR-005).
        throw new \RuntimeException('Not implemented');
    }

    public function view(User $user, User $subject): bool
    {
        // TODO(coder): self, or Super Admin.
        throw new \RuntimeException('Not implemented');
    }

    public function create(User $user): bool
    {
        // TODO(coder): Super Admin only — BR-003 forbids trainer self-registration.
        throw new \RuntimeException('Not implemented');
    }

    public function update(User $user, User $subject): bool
    {
        // TODO(coder): self (FR-016), or Super Admin.
        throw new \RuntimeException('Not implemented');
    }

    public function deactivate(User $user, User $subject): bool
    {
        // TODO(coder): Super Admin only (FR-017). Slice D.
        throw new \RuntimeException('Not implemented');
    }

    public function delete(User $user, User $subject): bool
    {
        // TODO(coder): Super Admin only (FR-018). Slice D.
        throw new \RuntimeException('Not implemented');
    }

    public function impersonate(User $user, User $subject): bool
    {
        // TODO(coder): Super Admin, target not Super Admin (BR-016), target active. Slice D.
        throw new \RuntimeException('Not implemented');
    }
}
