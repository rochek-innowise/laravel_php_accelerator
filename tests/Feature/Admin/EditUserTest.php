<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Livewire\Admin\EditUserForm;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/** FR-005: the Users tool's Edit row action — a Super Admin editing another user's profile. */
final class EditUserTest extends TestCase
{
    public function test_a_super_admin_edits_another_users_common_fields(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['first_name' => 'Old', 'last_name' => 'Name']);

        Livewire::actingAs($admin)
            ->test(EditUserForm::class, ['user' => $target])
            ->set(['firstName' => 'New', 'lastName' => 'Person', 'phone' => '+1 555 0177'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'first_name' => 'New',
            'last_name' => 'Person',
            'phone' => '+1 555 0177',
        ]);
    }

    public function test_a_non_admin_gets_403_on_the_edit_route(): void
    {
        $target = User::factory()->create();

        $this->actingAs(User::factory()->trainer()->create())
            ->get(route('admin.users.edit', $target))
            ->assertForbidden();

        $this->actingAs(User::factory()->role(Role::Player)->create())
            ->get(route('admin.users.edit', $target))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $target = User::factory()->create();

        $this->get(route('admin.users.edit', $target))->assertRedirect('/login');
    }

    public function test_role_and_status_cannot_be_changed_through_the_admin_edit_form(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->trainer()->status(UserStatus::Active)->create();

        Livewire::actingAs($admin)
            ->test(EditUserForm::class, ['user' => $target])
            ->set(['firstName' => 'Updated'])
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $target->fresh();

        $this->assertSame(Role::Trainer, $fresh->role);
        $this->assertSame(UserStatus::Active, $fresh->status);
    }
}
