<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Slice D Track C defect fix, beyond the plan's literal text: `AppServiceProvider::NOT_BYPASSABLE`
 * previously listed only `impersonate`, so the Super Admin `Gate::before` bypass returned `true`
 * for `deactivate`/`reactivate`/`delete` before `UserPolicy`'s own `! $user->is($subject)`
 * self-guard ever ran — a Super Admin could deactivate or GDPR-delete their *own* account and
 * lock the platform's last admin out irreversibly. This asserts the self-guard is now authoritative
 * (refused against self) while the ability still works normally against another user.
 */
final class SuperAdminSelfLifecycleTest extends TestCase
{
    public function test_a_super_admin_cannot_deactivate_themselves(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->assertFalse(Gate::forUser($admin)->allows('deactivate', $admin));
    }

    public function test_a_super_admin_can_deactivate_another_user(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $other = User::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('deactivate', $other));
    }

    public function test_a_super_admin_cannot_reactivate_themselves(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->assertFalse(Gate::forUser($admin)->allows('reactivate', $admin));
    }

    public function test_a_super_admin_can_reactivate_another_user(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $other = User::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('reactivate', $other));
    }

    public function test_a_super_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $admin));
    }

    public function test_a_super_admin_can_delete_another_user(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $other = User::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $other));
    }

    public function test_the_livewire_row_actions_refuse_self_targeting(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin);

        $this->assertTrue($admin->cannot('deactivate', $admin));
        $this->assertTrue($admin->cannot('reactivate', $admin));
        $this->assertTrue($admin->cannot('delete', $admin));
    }
}
