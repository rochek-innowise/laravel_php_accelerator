<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\Role;
use App\Models\PlayerProfile;
use App\Models\User;
use App\Support\Authorization\ChildAbilities;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class AuthorizationTest extends TestCase
{
    public function test_only_a_super_admin_may_list_users(): void
    {
        $this->assertTrue(User::factory()->superAdmin()->create()->can('viewAny', User::class));
        $this->assertFalse(User::factory()->trainer()->create()->can('viewAny', User::class));
        $this->assertFalse(User::factory()->coach()->create()->can('viewAny', User::class));
        $this->assertFalse(User::factory()->create()->can('viewAny', User::class));
    }

    public function test_a_user_may_update_only_their_own_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertTrue($user->can('update', $user));
        $this->assertFalse($user->can('update', $other));
    }

    /**
     * BR-016 regression: the Super Admin Gate::before bypass must not short-circuit this policy,
     * or an admin could impersonate another admin.
     */
    public function test_a_super_admin_cannot_impersonate_another_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $otherAdmin = User::factory()->superAdmin()->create();

        $this->assertFalse($admin->can('impersonate', $otherAdmin));
    }

    public function test_a_super_admin_can_impersonate_an_ordinary_user(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $player = User::factory()->create();

        $this->assertTrue($admin->can('impersonate', $player));
    }

    public function test_a_non_admin_cannot_impersonate(): void
    {
        $trainer = User::factory()->trainer()->create();
        $player = User::factory()->create();

        $this->assertFalse($trainer->can('impersonate', $player));
    }

    /**
     * The deny list must beat an ability that is otherwise granted — that is the only thing that
     * proves the Gate::before hook fires rather than the default deny.
     */
    public function test_the_child_deny_list_overrides_a_granted_ability(): void
    {
        foreach (ChildAbilities::DENIED as $ability) {
            Gate::define($ability, fn (User $user): bool => true);
        }

        $child = User::factory()->childAccount()->create();
        $adult = User::factory()->create();

        foreach (ChildAbilities::DENIED as $ability) {
            $this->assertFalse($child->can($ability), "Child was allowed [{$ability}].");
            $this->assertTrue($adult->can($ability), "Adult was denied [{$ability}].");
        }
    }

    public function test_a_child_account_cannot_create_player_profiles(): void
    {
        $child = User::factory()->childAccount()->create();
        $parent = User::factory()->role(Role::Player)->create();

        $this->assertFalse($child->can('create', PlayerProfile::class));
        $this->assertTrue($parent->can('create', PlayerProfile::class));
    }

    public function test_a_parent_may_manage_only_their_own_childrens_associations(): void
    {
        $parent = User::factory()->create();
        $stranger = User::factory()->create();
        $child = PlayerProfile::factory()->child()->create(['owner_user_id' => $parent->id]);

        $this->assertTrue($parent->can('manageTrainerAssociations', $child));
        $this->assertFalse($stranger->can('manageTrainerAssociations', $child));
    }
}
