<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use Tests\TestCase;

/**
 * Every screen fetched over HTTP, not through the Livewire test harness. The harness renders a
 * component in isolation; a full request also renders the layout, so a broken layout, a missing
 * route name or a null relationship in a Blade partial only shows up here.
 */
final class ScreenRenderTest extends TestCase
{
    public function test_the_trainer_profile_screen_renders_its_field_set(): void
    {
        $profile = TrainerProfile::factory()->create();

        $this->actingAs($profile->user)
            ->get('/profile')
            ->assertOk()
            ->assertSee('Business name')
            ->assertSee('Website')
            ->assertDontSee('Jersey number');
    }

    public function test_the_coach_profile_screen_renders_its_field_set(): void
    {
        $profile = CoachProfile::factory()->create();

        $this->actingAs($profile->user)
            ->get('/profile')
            ->assertOk()
            ->assertSee('Bio')
            ->assertSee('Certifications')
            ->assertDontSee('Business name');
    }

    public function test_the_player_profile_screen_renders_its_field_set(): void
    {
        $user = User::factory()->role(Role::Player)->create();
        PlayerProfile::factory()->selfProfile($user)->create(['skill_level' => 'Advanced']);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertSee('School')
            ->assertSee('Jersey number')
            ->assertSee('Advanced')
            ->assertDontSee('Bio');
    }

    public function test_a_user_without_a_profile_still_gets_a_working_screen(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/profile')
            ->assertOk()
            ->assertSee('First name')
            ->assertDontSee('Business name');
    }

    public function test_the_directory_and_edit_screens_render_for_a_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Edit')
            ->assertSee($target->email);

        $this->actingAs($admin)
            ->get("/admin/users/{$target->id}/edit")
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee('First name');
    }

    public function test_every_role_dashboard_renders(): void
    {
        // Pairs, not a keyed map: an enum cannot be an array key in PHP.
        foreach ([[Role::Trainer, '/trainer'], [Role::Coach, '/coach'], [Role::Player, '/player']] as [$role, $path]) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get($path)
                ->assertOk()
                ->assertSee('dashboard');
        }
    }
}
