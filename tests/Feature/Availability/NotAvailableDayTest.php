<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use App\Enums\DayOfWeek;
use App\Enums\Role;
use App\Livewire\Availability\Grid;
use App\Models\Availability;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Services\Availability\AvailabilityResolver;
use App\Services\Availability\CoachConflictChecker;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-014's "or 'Not Available'" half. The schema, the resolver, the CRM filter and
 * `CoachConflictChecker` all honoured `is_available = false` from the start; only the grid could
 * neither create such a day nor show one, which meant a save destroyed any that existed.
 */
final class NotAvailableDayTest extends TestCase
{
    #[Test]
    public function a_player_marks_a_day_not_available_and_it_survives_a_reload(): void
    {
        $player = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($player)->create();

        Livewire::actingAs($player)
            ->test(Grid::class)
            ->set('ranges', [])
            ->set('unavailableDays', [DayOfWeek::Sunday->value])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('unavailableDays', [DayOfWeek::Sunday->value]);

        $row = Availability::query()
            ->where('available_for_type', PlayerProfile::class)
            ->where('available_for_id', $self->id)
            ->sole();

        $this->assertFalse($row->is_available);
        $this->assertNull($row->start_time);
        $this->assertNull($row->end_time);
        $this->assertSame(DayOfWeek::Sunday, $row->day_of_week);
    }

    /**
     * The regression this whole change exists for. `SaveAvailability` replaces the set wholesale,
     * so a row the screen never loaded was a row the next save deleted — an unavailable day set by
     * a factory, an import, or an earlier session simply vanished, with nothing reported.
     */
    #[Test]
    public function saving_the_grid_no_longer_destroys_a_pre_existing_not_available_day(): void
    {
        $player = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($player)->create();

        Availability::factory()
            ->unavailable()
            ->for($self, 'availableFor')
            ->create(['day_of_week' => DayOfWeek::Sunday, 'trainer_profile_id' => null]);

        Livewire::actingAs($player)
            ->test(Grid::class)
            // The component loaded the existing unavailable day, so an unrelated edit preserves it.
            ->assertSet('unavailableDays', [DayOfWeek::Sunday->value])
            ->set('ranges', [['day_of_week' => DayOfWeek::Monday->value, 'start_time' => '17:00', 'end_time' => '20:00']])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(
            Availability::query()
                ->where('available_for_id', $self->id)
                ->where('day_of_week', DayOfWeek::Sunday)
                ->where('is_available', false)
                ->exists(),
            'The pre-existing Not Available day was destroyed by an unrelated save.',
        );
    }

    #[Test]
    public function a_day_cannot_be_both_not_available_and_carry_a_range(): void
    {
        $player = User::factory()->create();
        PlayerProfile::factory()->selfProfile($player)->create();

        Livewire::actingAs($player)
            ->test(Grid::class)
            ->set('ranges', [['day_of_week' => DayOfWeek::Monday->value, 'start_time' => '17:00', 'end_time' => '20:00']])
            ->set('unavailableDays', [DayOfWeek::Monday->value])
            ->call('save')
            ->assertHasErrors('ranges.0.day_of_week');
    }

    #[Test]
    public function a_forged_day_value_is_refused_rather_than_written(): void
    {
        $player = User::factory()->create();
        PlayerProfile::factory()->selfProfile($player)->create();

        Livewire::actingAs($player)
            ->test(Grid::class)
            ->set('ranges', [])
            ->set('unavailableDays', [99])
            ->call('save')
            ->assertHasErrors('unavailableDays');
    }

    #[Test]
    public function an_override_of_only_unavailable_days_still_wholly_replaces_the_default(): void
    {
        $player = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($player)->create();
        $trainer = TrainerProfile::factory()->create();

        Availability::factory()
            ->for($self, 'availableFor')
            ->create([
                'day_of_week' => DayOfWeek::Monday,
                'trainer_profile_id' => null,
                'start_time' => '17:00:00',
                'end_time' => '20:00:00',
            ]);

        Availability::factory()
            ->unavailable()
            ->for($self, 'availableFor')
            ->create(['day_of_week' => DayOfWeek::Monday, 'trainer_profile_id' => $trainer->id]);

        $resolved = app(AvailabilityResolver::class)->resolve($self, $trainer->id);

        // Decision 3: an override replaces the default wholly, so the default's Monday range is
        // not merged in alongside the override's Monday closure.
        $this->assertCount(1, $resolved);
        $this->assertFalse($resolved->first()->is_available);
    }

    #[Test]
    public function a_coach_marks_a_day_not_available_and_every_window_on_it_conflicts(): void
    {
        $coachUser = User::factory()->role(Role::Coach)->create();
        $employer = TrainerProfile::factory()->create();
        $coach = CoachProfile::factory()->create([
            'user_id' => $coachUser->id,
            'trainer_profile_id' => $employer->id,
        ]);

        Livewire::actingAs($coachUser)
            ->test(Grid::class)
            ->assertSet('isCoach', true)
            ->set('ranges', [])
            ->set('unavailableDays', [DayOfWeek::Wednesday->value])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(
            app(CoachConflictChecker::class)->hasConflict($coach, DayOfWeek::Wednesday, '10:00:00', '11:00:00'),
        );
    }
}
