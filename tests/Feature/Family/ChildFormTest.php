<?php

declare(strict_types=1);

namespace Tests\Feature\Family;

use App\Actions\Family\AssociatePlayersWithTrainer;
use App\Livewire\Family\ChildForm;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ChildFormTest extends TestCase
{
    #[Test]
    public function a_parent_creates_a_child_profile(): void
    {
        $parent = User::factory()->create();

        Livewire::actingAs($parent)
            ->test(ChildForm::class)
            ->set('name', 'Sam Rivera')
            ->set('birth_date', now()->subYears(8)->toDateString())
            ->set('gender', 'female')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('family.index'));

        $profile = PlayerProfile::where('name', 'Sam Rivera')->firstOrFail();
        $this->assertTrue($profile->is_child);
        $this->assertTrue($profile->isGuardedBy($parent));
        $this->assertSame('female', $profile->gender);
    }

    /** FR-008: gender is required, not merely offered. */
    #[Test]
    public function a_submission_without_gender_is_rejected(): void
    {
        $parent = User::factory()->create();

        Livewire::actingAs($parent)
            ->test(ChildForm::class)
            ->set('name', 'No Gender')
            ->set('birth_date', now()->subYears(8)->toDateString())
            ->call('save')
            ->assertHasErrors('gender');

        $this->assertDatabaseMissing('player_profiles', ['name' => 'No Gender']);
    }

    #[Test]
    public function an_out_of_range_birth_date_surfaces_a_field_error(): void
    {
        $parent = User::factory()->create();

        Livewire::actingAs($parent)
            ->test(ChildForm::class)
            ->set('name', 'Too Young')
            ->set('birth_date', now()->subMonths(2)->toDateString())
            ->set('gender', 'male')
            ->call('save')
            ->assertHasErrors('birth_date');

        $this->assertDatabaseMissing('player_profiles', ['name' => 'Too Young']);
    }

    #[Test]
    public function a_duplicate_within_the_family_shows_a_dismissible_warning_and_confirming_proceeds(): void
    {
        $parent = User::factory()->create();
        PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Alex Doe', 'birth_date' => '2015-03-01']);

        $component = Livewire::actingAs($parent)
            ->test(ChildForm::class)
            ->set('name', 'Alex Doe')
            ->set('birth_date', '2015-06-01')
            ->set('gender', 'male')
            ->call('save')
            ->assertHasErrors('name')
            ->assertSet('duplicateDetected', true);

        $this->assertSame(1, $parent->guardedPlayerProfiles()->count());

        $component
            ->set('confirmDuplicate', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('family.index'));

        $this->assertSame(2, $parent->guardedPlayerProfiles()->count());
    }

    #[Test]
    public function creating_a_child_with_a_login_toggle_creates_both_the_profile_and_the_account(): void
    {
        $parent = User::factory()->create();

        Livewire::actingAs($parent)
            ->test(ChildForm::class)
            ->set('name', 'Casey Lane')
            ->set('birth_date', now()->subYears(9)->toDateString())
            ->set('gender', 'other')
            ->set('wantsLogin', true)
            ->set('email', 'casey@example.test')
            ->set('password', 'correct-horse-battery-staple')
            ->set('password_confirmation', 'correct-horse-battery-staple')
            ->call('save')
            ->assertHasNoErrors();

        $profile = PlayerProfile::where('name', 'Casey Lane')->firstOrFail();

        $this->assertNotNull($profile->user_id);
        $this->assertTrue($profile->user->is_child_account);
        $this->assertSame('casey@example.test', $profile->user->email);
    }

    /**
     * FR-011. `/approvals` sits behind the `verified` middleware, and this flow issues no
     * `Registered` event, so without marking the login verified at creation the child it just made
     * a login for could never reach the screen FR-011 requires them to see.
     */
    #[Test]
    public function a_freshly_created_child_login_can_reach_the_approvals_screen(): void
    {
        $parent = User::factory()->create();

        Livewire::actingAs($parent)
            ->test(ChildForm::class)
            ->set('name', 'Approvals Kid')
            ->set('birth_date', now()->subYears(9)->toDateString())
            ->set('gender', 'other')
            ->set('wantsLogin', true)
            ->set('email', 'approvals-kid@example.test')
            ->set('password', 'correct-horse-battery-staple')
            ->set('password_confirmation', 'correct-horse-battery-staple')
            ->call('save')
            ->assertHasNoErrors();

        $child = PlayerProfile::where('name', 'Approvals Kid')->firstOrFail()->user;

        $this->assertNotNull($child);

        $this->actingAs($child)
            ->get(route('approvals.index'))
            ->assertOk();
    }

    #[Test]
    public function an_uploaded_photo_is_stored_full_size_with_no_thumbnail(): void
    {
        Storage::fake('local');
        $parent = User::factory()->create();

        Livewire::actingAs($parent)
            ->test(ChildForm::class)
            ->set('name', 'Photo Kid')
            ->set('birth_date', now()->subYears(7)->toDateString())
            ->set('gender', 'male')
            ->set('photo', UploadedFile::fake()->image('kid.jpg', 400, 400))
            ->call('save')
            ->assertHasNoErrors();

        $profile = PlayerProfile::where('name', 'Photo Kid')->firstOrFail();

        $this->assertNotNull($profile->photo_path);
        Storage::disk('local')->assertExists($profile->photo_path);
        // No thumbnail variant at all for a child photo (Decision 5) — only the original path.
        Storage::disk('local')->assertMissing(User::thumbnailPathFor($profile->photo_path));
        // A player's photo lives under its own namespace, distinct from `User`'s — `users.id` and
        // `player_profiles.id` are separate sequences, so a shared directory could otherwise put
        // an unrelated user's photo alongside this profile's own id.
        $this->assertStringContainsString('profile-photos/players/'.$profile->id.'/', $profile->photo_path);
    }

    #[Test]
    public function a_non_image_photo_upload_is_rejected(): void
    {
        Storage::fake('local');
        $parent = User::factory()->create();

        Livewire::actingAs($parent)
            ->test(ChildForm::class)
            ->set('name', 'Bad Photo Kid')
            ->set('birth_date', now()->subYears(7)->toDateString())
            ->set('gender', 'male')
            ->set('photo', UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['photo']);

        $this->assertDatabaseMissing('player_profiles', ['name' => 'Bad Photo Kid']);
    }

    #[Test]
    public function a_child_account_cannot_reach_the_form(): void
    {
        $child = User::factory()->childAccount()->create();

        Livewire::actingAs($child)
            ->test(ChildForm::class)
            ->assertForbidden();
    }

    #[Test]
    public function a_non_player_role_cannot_reach_the_route(): void
    {
        $trainer = User::factory()->trainer()->create();

        $this->actingAs($trainer)->get(route('family.children.create'))->assertForbidden();
    }

    /**
     * NFR-010: the checklist arrives on a public Livewire property, so a submitted trainer id is a
     * request, not a decision. A forged one used to write a `trainer_players` row inside an
     * organisation the family has no relationship with — enrolling a child with no ShareLink, no
     * invitation and no consent from that organisation, and rendering its name back on /family.
     */
    #[Test]
    public function a_forged_trainer_id_associates_nothing(): void
    {
        $parent = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($parent)->create();

        // Two reachable trainers, because the single-trainer branch takes a different path: this
        // has to exercise the checklist, which is what the multi-trainer parent sees.
        $reachable = TrainerProfile::factory()->create();
        $alsoReachable = TrainerProfile::factory()->create();
        $stranger = TrainerProfile::factory()->create();

        $associate = app(AssociatePlayersWithTrainer::class);
        $associate->handle($reachable, $parent, [$self->id]);
        $associate->handle($alsoReachable, $parent, [$self->id]);

        Livewire::actingAs($parent)
            ->test(ChildForm::class)
            ->set('name', 'Forged Target')
            ->set('birth_date', now()->subYears(9)->toDateString())
            ->set('gender', 'male')
            ->set('selectedTrainerIds', [$stranger->id])
            ->call('save')
            ->assertHasNoErrors();

        $profile = PlayerProfile::where('name', 'Forged Target')->firstOrFail();

        $this->assertSame(
            0,
            TrainerPlayer::withoutGlobalScopes()
                ->where('player_profile_id', $profile->id)
                ->count(),
            'A forged trainer id must associate the child with nobody at all.'
        );
    }

    /**
     * The other half of the same guard: dropping a forged id must not discard a legitimate one
     * submitted alongside it.
     */
    #[Test]
    public function a_legitimate_trainer_id_still_associates_when_submitted_beside_a_forged_one(): void
    {
        $parent = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($parent)->create();

        $reachable = TrainerProfile::factory()->create();
        $alsoReachable = TrainerProfile::factory()->create();
        $stranger = TrainerProfile::factory()->create();

        $associate = app(AssociatePlayersWithTrainer::class);
        $associate->handle($reachable, $parent, [$self->id]);
        $associate->handle($alsoReachable, $parent, [$self->id]);

        Livewire::actingAs($parent)
            ->test(ChildForm::class)
            ->set('name', 'Mixed Submission')
            ->set('birth_date', now()->subYears(9)->toDateString())
            ->set('gender', 'male')
            ->set('selectedTrainerIds', [$stranger->id, $reachable->id])
            ->call('save')
            ->assertHasNoErrors();

        $profile = PlayerProfile::where('name', 'Mixed Submission')->firstOrFail();

        $associations = TrainerPlayer::withoutGlobalScopes()
            ->where('player_profile_id', $profile->id)
            ->pluck('trainer_profile_id')
            ->all();

        $this->assertSame([$reachable->id], $associations);
    }
}
