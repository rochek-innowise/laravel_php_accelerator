<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
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

    /**
     * Step 11 of Slice D's plan: the shared layout's `--brand-primary` CSS variable must never
     * null-dereference for a Super Admin, who resolves no tenant at all (`EnsureTrainerContext`
     * gives them none) — it falls back to the platform default instead.
     */
    public function test_the_shared_layout_falls_back_to_the_platform_default_brand_color_for_a_super_admin(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin/users')
            ->assertOk()
            ->assertSee(
                '--brand-primary: '.config('branding.default_primary_color').';',
                false,
            );
    }

    /**
     * The other half of step 11's verification: every role that *does* resolve a tenant reflects
     * that tenant's own colour, not the platform default.
     */
    public function test_the_shared_layout_reflects_the_resolved_tenants_brand_color_for_every_tenant_scoped_role(): void
    {
        $trainer = User::factory()->trainer()->create();
        TrainerProfile::factory()->create(['user_id' => $trainer->id, 'primary_color' => '#111111']);

        $this->actingAs($trainer)
            ->get('/profile')
            ->assertOk()
            ->assertSee('--brand-primary: #111111;', false);

        $coach = User::factory()->coach()->create();
        $employer = TrainerProfile::factory()->create(['primary_color' => '#222222']);
        CoachProfile::factory()->create(['user_id' => $coach->id, 'trainer_profile_id' => $employer->id]);

        $this->actingAs($coach)
            ->get('/profile')
            ->assertOk()
            ->assertSee('--brand-primary: #222222;', false);

        // A Player reaches their tenant through `ResolvesAvailableTenants`, whose projection is
        // deliberately narrow (G-08). `primary_color` is part of that projection because FR-019
        // requires the brand colour to apply to *every* user in the organisation — a player who
        // saw the platform default instead would be a requirement miss, not a privacy win.
        $player = User::factory()->create();
        $profile = PlayerProfile::factory()->selfProfile($player)->create();
        $tenant = TrainerProfile::factory()->create(['primary_color' => '#333333']);
        TrainerPlayer::factory()->create([
            'trainer_profile_id' => $tenant->id,
            'player_profile_id' => $profile->id,
            'connected_at' => now(),
        ]);

        $this->actingAs($player)
            ->get('/profile')
            ->assertOk()
            ->assertSee('--brand-primary: #333333;', false);
    }
}
