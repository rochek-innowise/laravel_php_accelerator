<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Livewire\Admin\UsersTable;
use App\Models\PlayerProfile;
use App\Models\User;
use App\Models\UserDeletionLog;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Slice D Track C, step 9: the three lifecycle row actions on the Super Admin directory
 * (FR-017 deactivate/reactivate, FR-018 delete). The Actions themselves are covered by
 * UserLifecycleTest; this is the Livewire wiring and per-row visibility.
 */
final class UsersDirectoryLifecycleTest extends TestCase
{
    public function test_deactivate_flips_the_visible_status_chip_and_swaps_the_button_to_reactivate(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $component = Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->assertSeeHtml("deactivate({$target->id})")
            ->call('deactivate', $target->id)
            ->assertOk();

        $this->assertSame(UserStatus::Inactive, $target->fresh()->status);
        $component->assertSeeHtml("reactivate({$target->id})");
        $component->assertDontSeeHtml("deactivate({$target->id})");
    }

    public function test_reactivate_restores_active_status_and_swaps_the_button_back(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->status(UserStatus::Inactive)->create();

        $component = Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('reactivate', $target->id);

        $this->assertSame(UserStatus::Active, $target->fresh()->status);
        $component->assertSeeHtml("deactivate({$target->id})");
        $component->assertDontSeeHtml("reactivate({$target->id})");
    }

    public function test_delete_anonymizes_the_target_and_writes_a_deletion_log(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['email' => 'zin@example.test']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('delete', $target->id);

        $target->refresh();

        $this->assertSame(UserStatus::Deleted, $target->status);
        $this->assertSame("deleted_{$target->id}@deleted.invalid", $target->email);
        $this->assertNotNull(UserDeletionLog::where('original_user_id', $target->id)->first());
    }

    public function test_delete_button_disappears_once_a_user_is_already_deleted(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->status(UserStatus::Deleted)->create();

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->assertDontSeeHtml("delete({$target->id})")
            ->assertDontSeeHtml("deactivate({$target->id})")
            ->assertDontSeeHtml("reactivate({$target->id})");
    }

    /** `mount()`'s own `viewAny` guard already refuses a non-Super-Admin the whole component. */
    public function test_a_non_super_admin_is_refused_the_component_entirely(): void
    {
        $trainer = User::factory()->trainer()->create();

        Livewire::actingAs($trainer)
            ->test(UsersTable::class)
            ->assertForbidden();
    }

    /** FR-018: anonymizing a solely-guarded child cascades from the directory row too. */
    public function test_delete_cascades_to_a_solely_guarded_child_profile(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($target)->create(['name' => 'Solo Child']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->call('delete', $target->id);

        $this->assertSame('Deleted User', $child->fresh()->name);
    }
}
