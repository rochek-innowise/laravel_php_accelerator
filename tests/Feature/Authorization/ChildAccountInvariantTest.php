<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\PlayerProfile;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

/**
 * `users.is_child_account` is denormalized from the backing player profile's `is_child`, and it is
 * what the Gate::before deny list reads — a disagreement between the two silently hands a child
 * the abilities FR-011 forbids. The invariant is asserted over seeded data because that is the
 * only place both sides are written together; `User::factory()->childAccount()` deliberately
 * creates a bare login with no profile, for gate tests that need nothing else.
 */
final class ChildAccountInvariantTest extends TestCase
{
    public function test_every_seeded_child_login_has_a_child_profile(): void
    {
        $this->seed(DemoSeeder::class);

        $children = User::where('is_child_account', true)->get();

        $this->assertNotEmpty($children, 'The seeder no longer covers the child-login case.');

        foreach ($children as $child) {
            $profile = $child->playerProfile;

            $this->assertNotNull($profile, "Child login [{$child->email}] has no player profile.");
            $this->assertTrue($profile->is_child, "Profile of [{$child->email}] is not marked as a child.");
        }
    }

    public function test_no_seeded_child_profile_is_backed_by_an_adult_login(): void
    {
        $this->seed(DemoSeeder::class);

        $backed = PlayerProfile::where('is_child', true)->whereNotNull('user_id')->get();

        $this->assertNotEmpty($backed, 'The seeder no longer covers a child holding its own login.');

        foreach ($backed as $profile) {
            $this->assertTrue(
                $profile->user->is_child_account,
                "Profile [{$profile->name}] is a child but its login is not flagged as one.",
            );
        }
    }

    /** A parent who also trains is an adult account with an adult self profile (BR-022). */
    public function test_a_self_profile_does_not_flag_its_owner_as_a_child(): void
    {
        $this->seed(DemoSeeder::class);

        $parent = User::where('email', 'parent@example.test')->firstOrFail();

        $this->assertFalse($parent->is_child_account);
        $this->assertFalse($parent->playerProfile->is_child);
    }
}
