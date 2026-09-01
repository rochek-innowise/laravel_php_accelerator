<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CoachProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoachProfile>
 */
class CoachProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->coach(),
            'trainer_profile_id' => TrainerProfile::factory(),
            'status' => 'active',
            'bio' => fake()->paragraph(),
            'credentials' => fake()->sentence(),
            'certifications' => fake()->sentence(),
            'is_public' => true,
            'joined_at' => now(),
        ];
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'invited',
            'joined_at' => null,
        ]);
    }
}
