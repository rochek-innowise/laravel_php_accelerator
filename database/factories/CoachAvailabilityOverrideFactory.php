<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CoachAvailabilityOverride;
use App\Models\CoachProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoachAvailabilityOverride>
 */
class CoachAvailabilityOverrideFactory extends Factory
{
    /**
     * @return array<model-property<CoachAvailabilityOverride>, mixed>
     */
    public function definition(): array
    {
        $coach = CoachProfile::factory()->create();

        return [
            'coach_profile_id' => $coach->id,
            'trainer_profile_id' => $coach->trainer_profile_id,
            'event_id' => null,
            'reason' => fake()->sentence(),
        ];
    }
}
