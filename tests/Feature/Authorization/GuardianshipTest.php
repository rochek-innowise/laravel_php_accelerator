<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\PlayerProfile;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

/**
 * The case `owner_user_id` could not express: a child with two guardians, and a guardian with
 * several children. Authorization now runs through the pivot, so both directions matter.
 */
final class GuardianshipTest extends TestCase
{
    public function test_a_child_can_have_two_guardians_who_both_reach_the_profile(): void
    {
        $mother = User::factory()->create();
        $father = User::factory()->create();

        $child = PlayerProfile::factory()
            ->child()
            ->guardedBy($mother, relationship: 'mother')
            ->guardedBy($father, isPrimary: false, relationship: 'father')
            ->create();

        $this->assertCount(2, $child->guardians);

        foreach ([$mother, $father] as $guardian) {
            $this->assertTrue($guardian->can('view', $child));
            $this->assertTrue($guardian->can('update', $child));
            $this->assertTrue($guardian->can('manageTrainerAssociations', $child));
        }
    }

    public function test_a_guardian_reaches_several_children(): void
    {
        $parent = User::factory()->create();

        $first = PlayerProfile::factory()->child()->guardedBy($parent)->create();
        $second = PlayerProfile::factory()->child()->guardedBy($parent)->create();

        $this->assertCount(2, $parent->guardedPlayerProfiles);
        $this->assertTrue($parent->can('update', $first));
        $this->assertTrue($parent->can('update', $second));
    }

    public function test_a_stranger_reaches_neither(): void
    {
        $stranger = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy(User::factory()->create())->create();

        $this->assertFalse($stranger->can('view', $child));
        $this->assertFalse($stranger->can('update', $child));
        $this->assertFalse($stranger->can('manageTrainerAssociations', $child));
    }

    /** FR-011: a child never manages their own trainer associations, guardian or not. */
    public function test_a_child_holding_its_own_login_cannot_manage_associations(): void
    {
        $childLogin = User::factory()->childAccount()->create();
        $parent = User::factory()->create();

        $child = PlayerProfile::factory()
            ->child()
            ->guardedBy($parent)
            ->create(['user_id' => $childLogin->id]);

        $this->assertTrue($childLogin->can('view', $child));
        $this->assertFalse($childLogin->can('manageTrainerAssociations', $child));
    }

    /** A self profile carries no guardian row; it is reached through user_id alone. */
    public function test_a_self_profile_has_no_guardians(): void
    {
        $user = User::factory()->create();
        $profile = PlayerProfile::factory()->selfProfile($user)->create();

        $this->assertCount(0, $profile->guardians);
        $this->assertTrue($user->can('update', $profile));
        $this->assertFalse($user->isParent());
    }

    public function test_parenthood_is_emergent_from_guarding_a_child(): void
    {
        $parent = User::factory()->create();

        $this->assertFalse($parent->isParent());

        PlayerProfile::factory()->child()->guardedBy($parent)->create();

        $this->assertTrue($parent->fresh()->isParent());
    }

    /** The seeded scenario must exercise the two-guardian case, or nothing does by default. */
    public function test_the_seeder_covers_a_child_with_two_guardians(): void
    {
        $this->seed(DemoSeeder::class);

        $maya = PlayerProfile::where('name', 'Maya Miles')->firstOrFail();

        $this->assertCount(2, $maya->guardians);
        $this->assertSame(1, $maya->guardians->where('pivot.is_primary', true)->count());
    }
}
