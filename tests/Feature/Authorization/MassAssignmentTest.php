<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Tests\TestCase;

/** The privilege columns decide who you are, so they must not be reachable by mass assignment. */
final class MassAssignmentTest extends TestCase
{
    public function test_role_cannot_be_mass_assigned_on_create(): void
    {
        $user = new User([
            'email' => 'escalate@example.test',
            'password' => 'password',
            'first_name' => 'Es',
            'last_name' => 'Calate',
            'role' => Role::SuperAdmin,
        ]);

        $this->assertNull($user->role);
    }

    public function test_role_and_status_cannot_be_mass_assigned_on_update(): void
    {
        $user = User::factory()->create();

        $user->update([
            'role' => Role::SuperAdmin,
            'status' => UserStatus::Inactive,
            'is_child_account' => true,
            'first_name' => 'Legitimate',
        ]);

        $fresh = $user->fresh();

        $this->assertSame(Role::Player, $fresh->role);
        $this->assertSame(UserStatus::Active, $fresh->status);
        $this->assertFalse($fresh->is_child_account);
        $this->assertSame('Legitimate', $fresh->first_name);
    }
}
