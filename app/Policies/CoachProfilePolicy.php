<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CoachProfile;
use App\Models\User;

/** Order: tenant membership -> role -> child deny list (AD-005). */
final class CoachProfilePolicy
{
    public function viewAny(User $user): bool
    {
        // TODO(coder): trainer of the current tenant, or Super Admin.
        throw new \RuntimeException('Not implemented');
    }

    public function view(User $user, CoachProfile $coachProfile): bool
    {
        // TODO(coder): the coach themselves, their trainer, or Super Admin.
        throw new \RuntimeException('Not implemented');
    }

    public function invite(User $user): bool
    {
        // TODO(coder): trainer only (FR-013). Slice B.
        throw new \RuntimeException('Not implemented');
    }

    public function update(User $user, CoachProfile $coachProfile): bool
    {
        // TODO(coder): the coach edits their own profile; the trainer edits the association.
        throw new \RuntimeException('Not implemented');
    }
}
