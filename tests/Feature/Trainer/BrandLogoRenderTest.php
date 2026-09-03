<?php

declare(strict_types=1);

namespace Tests\Feature\Trainer;

use App\Enums\CoachStatus;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gap 11. `TrainerProfile::logoUrl()` documents itself as "meant to render for every member of the
 * organisation on every page load" — until now nothing called it, the same seam bug `primary_color`
 * had before step 11 wired that one into the shared layout.
 */
final class BrandLogoRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    #[Test]
    public function the_trainer_sees_their_own_logo_on_every_page(): void
    {
        $trainer = TrainerProfile::factory()->create(['logo_path' => 'branding/1/logo.png']);
        Storage::disk('public')->put($trainer->logo_path, 'fake-image-bytes');

        $this->actingAs($trainer->user)
            ->get('/profile')
            ->assertOk()
            ->assertSee($trainer->logoUrl(), false);
    }

    #[Test]
    public function a_coach_in_the_organisation_sees_the_trainers_logo(): void
    {
        $trainer = TrainerProfile::factory()->create(['logo_path' => 'branding/2/logo.png']);
        Storage::disk('public')->put($trainer->logo_path, 'fake-image-bytes');
        $coach = CoachProfile::factory()->create([
            'trainer_profile_id' => $trainer->id,
            'status' => CoachStatus::Active,
            'joined_at' => now(),
        ]);

        $this->actingAs($coach->user)
            ->get('/profile')
            ->assertOk()
            ->assertSee($trainer->logoUrl(), false);
    }

    #[Test]
    public function a_player_rostered_with_the_organisation_sees_the_trainers_logo(): void
    {
        $trainer = TrainerProfile::factory()->create(['logo_path' => 'branding/3/logo.png']);
        Storage::disk('public')->put($trainer->logo_path, 'fake-image-bytes');
        $player = User::factory()->create();
        $profile = PlayerProfile::factory()->selfProfile($player)->create();
        TrainerPlayer::factory()->create(['trainer_profile_id' => $trainer->id, 'player_profile_id' => $profile->id]);

        $this->actingAs($player)
            ->get('/profile')
            ->assertOk()
            ->assertSee($trainer->logoUrl(), false);
    }

    #[Test]
    public function a_trainer_with_no_logo_yet_renders_no_broken_image(): void
    {
        $trainer = TrainerProfile::factory()->create(['logo_path' => null]);

        $this->actingAs($trainer->user)
            ->get('/profile')
            ->assertOk()
            ->assertDontSee('<img', false);
    }

    /** A Super Admin resolves no tenant at all (`EnsureTrainerContext` never gives them one). */
    #[Test]
    public function a_super_admin_with_no_active_tenant_renders_no_logo(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get('/profile')
            ->assertOk()
            ->assertDontSee('<img', false);
    }
}
