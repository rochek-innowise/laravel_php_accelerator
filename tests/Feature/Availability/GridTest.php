<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use App\Enums\DayOfWeek;
use App\Livewire\Availability\Grid;
use App\Livewire\Context\ProfileSwitcher;
use App\Models\Availability;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Services\Availability\AvailabilityResolver;
use App\Support\Tenancy\TrainerContext;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-014/FR-015. Shared component, two routes — `/availability` (player/parent) and
 * `/coach/my-times` (coach); the coach branch renders no default/override toggle at all.
 */
final class GridTest extends TestCase
{
    #[Test]
    public function a_player_sets_and_reloads_their_default_best_times(): void
    {
        $player = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($player)->create();

        Livewire::actingAs($player)
            ->test(Grid::class)
            ->assertSet('trainerProfileId', null)
            ->set('ranges', [['day_of_week' => 1, 'start_time' => '17:00', 'end_time' => '20:00']])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('availabilities', [
            'available_for_type' => PlayerProfile::class,
            'available_for_id' => $self->id,
            'trainer_profile_id' => null,
            'day_of_week' => 1,
        ]);

        Livewire::actingAs($player)
            ->test(Grid::class)
            ->assertSet('ranges', [['day_of_week' => 1, 'start_time' => '17:00', 'end_time' => '20:00']]);
    }

    #[Test]
    public function a_parent_sets_an_override_for_the_active_trainer_without_touching_the_default(): void
    {
        $parent = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create();
        $trainer = TrainerProfile::factory()->create();
        TrainerPlayer::factory()->create(['trainer_profile_id' => $trainer->id, 'player_profile_id' => $child->id]);

        Availability::factory()->forSubject($child)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
        ]);

        session([ProfileSwitcher::SESSION_KEY => $child->id]);
        app(TrainerContext::class)->set($trainer);

        Livewire::actingAs($parent)
            ->test(Grid::class)
            ->assertSet('trainerProfileId', $trainer->id)
            ->set('ranges', [['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '11:00']])
            ->call('save')
            ->assertHasNoErrors();

        $resolver = app(AvailabilityResolver::class);

        $default = $resolver->resolve($child, null);
        $this->assertCount(1, $default);
        $this->assertSame(DayOfWeek::Monday, $default->first()->day_of_week, 'The default set must survive the override untouched.');

        $override = $resolver->resolve($child, $trainer->id);
        $this->assertCount(1, $override);
        $this->assertSame(DayOfWeek::Tuesday, $override->first()->day_of_week);
        $this->assertFalse($resolver->isUsingDefault($child, $trainer->id));
    }

    #[Test]
    public function reset_to_default_removes_the_override(): void
    {
        $parent = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create();
        $trainer = TrainerProfile::factory()->create();
        TrainerPlayer::factory()->create(['trainer_profile_id' => $trainer->id, 'player_profile_id' => $child->id]);

        Availability::factory()->forSubject($child)->create(['day_of_week' => DayOfWeek::Monday]);
        Availability::factory()->forSubject($child)->override($trainer)->create(['day_of_week' => DayOfWeek::Tuesday]);

        session([ProfileSwitcher::SESSION_KEY => $child->id]);
        app(TrainerContext::class)->set($trainer);

        Livewire::actingAs($parent)
            ->test(Grid::class)
            ->assertSet('trainerProfileId', $trainer->id)
            ->call('resetToDefault')
            ->assertHasNoErrors();

        $resolver = app(AvailabilityResolver::class);
        $this->assertTrue($resolver->isUsingDefault($child, $trainer->id));
        $this->assertSame(DayOfWeek::Monday, $resolver->resolve($child, $trainer->id)->first()->day_of_week);
    }

    #[Test]
    public function a_coachs_page_shows_no_default_override_toggle_and_writes_against_their_own_trainer(): void
    {
        $coachProfile = CoachProfile::factory()->create();

        Livewire::actingAs($coachProfile->user)
            ->test(Grid::class)
            ->assertSet('isCoach', true)
            ->assertSet('trainerProfileId', $coachProfile->trainer_profile_id)
            ->assertDontSee('Reset to default')
            ->set('ranges', [['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '10:00']])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('availabilities', [
            'available_for_type' => CoachProfile::class,
            'available_for_id' => $coachProfile->id,
            'trainer_profile_id' => $coachProfile->trainer_profile_id,
            'day_of_week' => 3,
        ]);
    }

    #[Test]
    public function a_coach_may_not_reset_to_default_there_is_no_such_concept_for_a_coach(): void
    {
        $coachProfile = CoachProfile::factory()->create();

        Livewire::actingAs($coachProfile->user)
            ->test(Grid::class)
            ->call('resetToDefault')
            ->assertForbidden();
    }

    #[Test]
    public function a_stranger_with_the_wrong_role_is_refused_both_routes(): void
    {
        $this->actingAs(User::factory()->trainer()->create())
            ->get(route('availability'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get(route('coach.my-times'))
            ->assertForbidden();
    }

    /**
     * The session key is re-validated against the actor's own `trainableProfiles()`, never
     * trusted blindly — a non-guardian whose session was forged to point at a child they do not
     * guard gets 403, not the wrong family's data.
     */
    #[Test]
    public function a_non_guardian_with_a_forged_session_selection_is_refused(): void
    {
        $stranger = User::factory()->create();
        $someoneElse = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($someoneElse)->create();

        session([ProfileSwitcher::SESSION_KEY => $child->id]);

        Livewire::actingAs($stranger)->test(Grid::class)->assertForbidden();
    }

    /**
     * Gap 7: `'9:00' <= '10:00'` is false under a *string* comparison — '1' sorts before '9' — so
     * this exact range used to be rejected as "end before start" even though 10:00 genuinely comes
     * after 9:00. Comparing as times, not strings, fixes it.
     */
    #[Test]
    public function a_single_digit_hour_start_time_is_not_misread_as_after_a_double_digit_end_time(): void
    {
        $player = User::factory()->create();
        PlayerProfile::factory()->selfProfile($player)->create();

        Livewire::actingAs($player)
            ->test(Grid::class)
            ->set('ranges', [['day_of_week' => 1, 'start_time' => '9:00', 'end_time' => '10:00']])
            ->call('save')
            ->assertHasNoErrors();
    }

    /**
     * Gap 7: a malformed value must never reach the `TIME` column as a concatenated string — that
     * is a 500 under strict SQL mode, not a validation error.
     */
    #[Test]
    public function a_malformed_time_is_a_field_error_not_a_500(): void
    {
        $player = User::factory()->create();
        PlayerProfile::factory()->selfProfile($player)->create();

        Livewire::actingAs($player)
            ->test(Grid::class)
            ->set('ranges', [['day_of_week' => 1, 'start_time' => 'abc', 'end_time' => 'abd']])
            ->call('save')
            ->assertHasErrors(['ranges.0.start_time']);
    }

    /**
     * Gap 7: a browser's `<input type="time" step="...">` can submit seconds. Concatenating that
     * straight onto `:00` used to produce `'17:00:00:00'` for the `TIME` column — a 500, not a
     * field error.
     */
    #[Test]
    public function a_time_carrying_seconds_is_a_field_error_not_a_500(): void
    {
        $player = User::factory()->create();
        PlayerProfile::factory()->selfProfile($player)->create();

        Livewire::actingAs($player)
            ->test(Grid::class)
            ->set('ranges', [['day_of_week' => 1, 'start_time' => '17:00:00', 'end_time' => '20:00']])
            ->call('save')
            ->assertHasErrors(['ranges.0.start_time']);
    }

    /** An empty Start field must report on Start, not on End (the bug the missing `<x-slot:error>` masked). */
    #[Test]
    public function an_empty_start_time_reports_its_own_error(): void
    {
        $player = User::factory()->create();
        PlayerProfile::factory()->selfProfile($player)->create();

        Livewire::actingAs($player)
            ->test(Grid::class)
            ->set('ranges', [['day_of_week' => 1, 'start_time' => '', 'end_time' => '20:00']])
            ->call('save')
            ->assertHasErrors(['ranges.0.start_time'])
            ->assertHasNoErrors(['ranges.0.end_time']);
    }

    /** A missing array key must not be an undefined-index warning — it is simply an invalid, empty time. */
    #[Test]
    public function a_missing_time_key_is_a_field_error_not_a_warning(): void
    {
        $player = User::factory()->create();
        PlayerProfile::factory()->selfProfile($player)->create();

        Livewire::actingAs($player)
            ->test(Grid::class)
            ->set('ranges', [['day_of_week' => 1, 'start_time' => '17:00']])
            ->call('save')
            ->assertHasErrors(['ranges.0.end_time']);
    }
}
