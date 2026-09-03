<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use App\Enums\DayOfWeek;
use App\Enums\TrainerPlayerStatus;
use App\Models\Availability;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Services\Availability\AvailabilityResolver;
use App\Support\Tenancy\TrainerContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-014's CRM filter (Gap 5), tested directly against the resolver method — no Livewire
 * component calls it yet. Seeds a mixed default/override roster and asserts the exact expected
 * set for one day/window, with the "override replaces, does not merge with, the default" case
 * asserted explicitly (a player whose default would match but whose override does not).
 */
final class RosterAvailableAtTest extends TestCase
{
    #[Test]
    public function it_returns_exactly_the_players_free_for_the_given_window(): void
    {
        $trainer = TrainerProfile::factory()->create();
        $otherTrainer = TrainerProfile::factory()->create();

        app(TrainerContext::class)->set($trainer);

        // A: on the roster, default covers the window, no override for this trainer -> included.
        $playerA = PlayerProfile::factory()->create(['name' => 'Default Match']);
        Availability::factory()->forSubject($playerA)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
        ]);
        $this->associate($trainer, $playerA);

        // B: default covers the window, but this trainer's override does NOT -> excluded. Pins
        // Decision 3: the override replaces the default, it is never merged with it.
        $playerB = PlayerProfile::factory()->create(['name' => 'Override Blocks It']);
        Availability::factory()->forSubject($playerB)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
        ]);
        Availability::factory()->forSubject($playerB)->override($trainer)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);
        $this->associate($trainer, $playerB);

        // C: no default at all, override for this trainer covers the window -> included.
        $playerC = PlayerProfile::factory()->create(['name' => 'Override Only']);
        Availability::factory()->forSubject($playerC)->override($trainer)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '16:00:00',
            'end_time' => '21:00:00',
        ]);
        $this->associate($trainer, $playerC);

        // D: on the roster, default does not cover the window (wrong day) -> excluded.
        $playerD = PlayerProfile::factory()->create(['name' => 'Wrong Day']);
        Availability::factory()->forSubject($playerD)->create([
            'day_of_week' => DayOfWeek::Tuesday,
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
        ]);
        $this->associate($trainer, $playerD);

        // E: has an override for a DIFFERENT trainer covering the window; no override for THIS
        // trainer, and their default does not cover it -> excluded (the other trainer's override
        // must not leak into this trainer's roster filter).
        $playerE = PlayerProfile::factory()->create(['name' => 'Other Trainers Override']);
        Availability::factory()->forSubject($playerE)->create([
            'day_of_week' => DayOfWeek::Wednesday,
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
        ]);
        Availability::factory()->forSubject($playerE)->override($otherTrainer)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '16:00:00',
            'end_time' => '21:00:00',
        ]);
        $this->associate($trainer, $playerE);

        // F: default covers the window, but not on this trainer's roster at all -> excluded.
        $playerF = PlayerProfile::factory()->create(['name' => 'Not On Roster']);
        Availability::factory()->forSubject($playerF)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
        ]);

        // G: default covers the window, associated but the association is inactive -> excluded.
        $playerG = PlayerProfile::factory()->create(['name' => 'Inactive Association']);
        Availability::factory()->forSubject($playerG)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
        ]);
        $this->associate($trainer, $playerG, TrainerPlayerStatus::Inactive);

        $result = app(AvailabilityResolver::class)
            ->rosterAvailableAt($trainer, DayOfWeek::Monday, '17:00:00', '20:00:00')
            ->pluck('name');

        $this->assertEqualsCanonicalizing(['Default Match', 'Override Only'], $result->all());
    }

    protected function associate(
        TrainerProfile $trainer,
        PlayerProfile $player,
        TrainerPlayerStatus $status = TrainerPlayerStatus::Active,
    ): TrainerPlayer {
        return TrainerPlayer::factory()->create([
            'trainer_profile_id' => $trainer->id,
            'player_profile_id' => $player->id,
            'status' => $status,
        ]);
    }
}
