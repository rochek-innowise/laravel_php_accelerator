<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DayOfWeek;
use App\Models\Availability;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Availability>
 */
class AvailabilityFactory extends Factory
{
    /**
     * @return array<model-property<Availability>, mixed>
     */
    public function definition(): array
    {
        return [
            'available_for_type' => PlayerProfile::class,
            'available_for_id' => PlayerProfile::factory(),
            'trainer_profile_id' => null,
            'day_of_week' => fake()->randomElement(DayOfWeek::cases()),
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
            'is_available' => true,
        ];
    }

    /**
     * Attaches the row to an already-existing subject instead of minting a fresh one. Named
     * `forSubject`, not `for` — `Factory::for()` is the built-in belongsTo-relationship helper and
     * must not be shadowed by an incompatible signature.
     */
    public function forSubject(Model $subject): static
    {
        return $this->state(fn (array $attributes) => [
            'available_for_type' => $subject::class,
            'available_for_id' => $subject->getKey(),
        ]);
    }

    /** An override set for one specific trainer, rather than the NULL default set. */
    public function override(TrainerProfile|int $trainer): static
    {
        return $this->state(fn (array $attributes) => [
            'trainer_profile_id' => $trainer instanceof TrainerProfile ? $trainer->getKey() : $trainer,
        ]);
    }

    /** A coach's own row: always attached to a `CoachProfile`, always trainer-specific. */
    public function coach(?CoachProfile $coach = null): static
    {
        $coach ??= CoachProfile::factory()->create();

        return $this->state(fn (array $attributes) => [
            'available_for_type' => CoachProfile::class,
            'available_for_id' => $coach->getKey(),
            'trainer_profile_id' => $coach->trainer_profile_id,
        ]);
    }

    /** A "Not Available" day: one row, `is_available = false`, no times. */
    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => null,
            'end_time' => null,
            'is_available' => false,
        ]);
    }
}
