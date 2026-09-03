<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\ShareLink\GeneratePlayerShareLink;
use App\Enums\DayOfWeek;
use App\Enums\TrainerPlayerStatus;
use App\Models\Availability;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\ShareLink;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The hard scenario, end to end: if a change breaks isolation or the family model, this seeder
 * is what makes it visible.
 *
 * Every account below uses the password `password` and a fixed, memorable address, so each role
 * can actually be logged into by hand. Trainer and coach owners used to take the factory's random
 * `safeEmail()`, which made two of the four roles impossible to sign in as while testing.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->superAdmin()->create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin@example.test',
        ]);

        $trainerOne = TrainerProfile::factory()->create([
            'user_id' => User::factory()->trainer()->create([
                'first_name' => 'Elite',
                'last_name' => 'Owner',
                'email' => 'trainer@example.test',
            ])->id,
            'business_name' => 'Elite Basketball Academy',
            'primary_color' => '#C2410C',
        ]);

        TrainerProfile::factory()->create([
            'user_id' => User::factory()->trainer()->create([
                'first_name' => 'Northside',
                'last_name' => 'Owner',
                'email' => 'trainer2@example.test',
            ])->id,
            'business_name' => 'Northside Volleyball',
            'primary_color' => '#1D4ED8',
        ]);

        $coach = CoachProfile::factory()->create([
            'user_id' => User::factory()->coach()->create([
                'first_name' => 'Chris',
                'last_name' => 'Coach',
                'email' => 'coach@example.test',
            ])->id,
            'trainer_profile_id' => $trainerOne->id,
        ]);

        $parent = User::factory()->create([
            'first_name' => 'Sarah',
            'last_name' => 'Miles',
            'email' => 'parent@example.test',
        ]);

        // Every Player/Parent account gets a self profile, so "parent who also trains" (BR-022)
        // is the ordinary case rather than a branch.
        PlayerProfile::factory()->selfProfile($parent)->create();

        PlayerProfile::factory()->child()->guardedBy($parent, relationship: 'mother')->create([
            'name' => 'Alex Miles',
        ]);

        $secondGuardian = User::factory()->create([
            'first_name' => 'Taras',
            'last_name' => 'Miles',
            'email' => 'parent2@example.test',
        ]);

        $secondChildLogin = User::factory()->childAccount()->create([
            'first_name' => 'Maya',
            'last_name' => 'Miles',
            'email' => 'child@example.test',
        ]);

        // Maya has two guardians, which is the case `owner_user_id` could not express.
        $maya = PlayerProfile::factory()
            ->child()
            ->guardedBy($parent, relationship: 'mother')
            ->guardedBy($secondGuardian, isPrimary: false, relationship: 'father')
            ->create([
                'user_id' => $secondChildLogin->id,
                'name' => 'Maya Miles',
            ]);

        $this->seedAssociations($trainerOne, $parent, $maya);
        $this->seedAvailability($trainerOne, $parent->playerProfile()->firstOrFail(), $maya, $coach);
    }

    /**
     * Slice B's half of the scenario: a static player link per organisation, a pending coach
     * invitation, and one child reachable from *both* organisations — the case that catches a
     * tenancy regression, because Maya must appear in each roster without either seeing the other.
     */
    protected function seedAssociations(TrainerProfile $trainerOne, User $parent, PlayerProfile $maya): void
    {
        $trainerTwo = TrainerProfile::where('business_name', 'Northside Volleyball')->firstOrFail();
        $owner = $trainerOne->user()->firstOrFail();

        $generate = app(GeneratePlayerShareLink::class);
        $linkOne = $generate->handle($trainerOne, $owner);
        $generate->handle($trainerTwo, $trainerTwo->user()->firstOrFail());

        $parentProfile = $parent->playerProfile()->firstOrFail();

        // The parent trains with one organisation; Maya trains with both.
        $this->associate($trainerOne, $parentProfile->id, $linkOne->id);
        $this->associate($trainerOne, $maya->id, $linkOne->id);
        $this->associate($trainerTwo, $maya->id, null);

        // A coach invitation left pending, so the Coaches screen has all three states to render.
        ShareLink::factory()->coach('pending.coach@example.test')->create([
            'trainer_profile_id' => $trainerOne->id,
            'created_by_user_id' => $owner->id,
        ]);
    }

    /**
     * Slice D's half: all three availability shapes, so the Best Times screen is not empty and a
     * regression in Decision 3's "an override wholly replaces the default" rule is visible by eye.
     *
     * - The parent keeps a plain default set (`trainer_profile_id` NULL), applying everywhere.
     * - Maya has a default set *and* an override for Elite, so the two organisations legitimately
     *   see different times for the same child — the case the nullable column exists for.
     * - The coach gets their own fixed weekly schedule plus one "Not Available" day (FR-014's
     *   other half), which is what makes `CoachConflictChecker` report a conflict for that day.
     */
    protected function seedAvailability(
        TrainerProfile $trainerOne,
        PlayerProfile $parentProfile,
        PlayerProfile $maya,
        CoachProfile $coach,
    ): void {
        foreach ([DayOfWeek::Tuesday, DayOfWeek::Thursday] as $day) {
            Availability::factory()->forSubject($parentProfile)->create([
                'day_of_week' => $day,
                'start_time' => '18:00:00',
                'end_time' => '21:00:00',
            ]);
        }

        Availability::factory()->forSubject($maya)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '16:00:00',
            'end_time' => '19:00:00',
        ]);

        // The override: for Elite only, Maya trains earlier. Northside still reads the default.
        Availability::factory()->forSubject($maya)->override($trainerOne)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '15:00:00',
            'end_time' => '17:00:00',
        ]);

        foreach ([DayOfWeek::Monday, DayOfWeek::Wednesday] as $day) {
            Availability::factory()->coach($coach)->create([
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '13:00:00',
            ]);
        }

        Availability::factory()->coach($coach)->unavailable()->create([
            'day_of_week' => DayOfWeek::Sunday,
        ]);
    }

    protected function associate(TrainerProfile $trainer, int $playerProfileId, ?int $shareLinkId): void
    {
        $association = new TrainerPlayer(['status' => TrainerPlayerStatus::Active]);

        $association->forceFill([
            'trainer_profile_id' => $trainer->id,
            'player_profile_id' => $playerProfileId,
            'share_link_id' => $shareLinkId,
            'connected_at' => now(),
        ])->save();
    }
}
