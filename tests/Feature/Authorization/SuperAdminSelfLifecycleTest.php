<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserStatus;
use App\Livewire\Admin\UsersTable;
use App\Models\ShareLink;
use App\Models\TrainerPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
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

    /**
     * Finding 9 (test quality): this replaces a test of the same name that never touched
     * `UsersTable` at all — it re-ran the plain `Gate::forUser()` checks above via
     * `$admin->cannot(...)`, which duplicates them and pins nothing about the Livewire row action
     * its name promised. This version actually calls `UsersTable::delete()` acting as the admin.
     * It also serves finding 5's "still cannot delete their own User" direction, alongside the
     * ShareLink/TrainerPlayer tests below for the "still can" direction.
     */
    public function test_the_livewire_row_action_refuses_self_targeting(): void
    {
        $admin = User::factory()->superAdmin()->create();

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('delete', $admin->id)
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $admin->fresh()->status);
    }

    /**
     * Finding 5: `delete` is shared with ShareLinkPolicy/TrainerPlayerPolicy, both of which
     * hard-require `role === Role::Trainer` and would refuse a Super Admin outright if the
     * `NOT_BYPASSABLE` self-guard fix were made ability-name-wide instead of subject-scoped.
     */
    public function test_a_super_admin_can_still_delete_a_share_link(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $shareLink = ShareLink::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $shareLink));
    }

    public function test_a_super_admin_can_still_delete_a_trainer_player_association(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $trainerPlayer = TrainerPlayer::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $trainerPlayer));
    }
}
