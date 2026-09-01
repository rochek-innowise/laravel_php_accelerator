<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlayerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerProfile>
 */
class PlayerProfileFactory extends Factory
{
    /**
     * @return array<model-property<PlayerProfile>, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'user_id' => null,
            'name' => fake()->name(),
            'birth_date' => fake()->dateTimeBetween('-40 years', '-19 years'),
            'gender' => fake()->randomElement(['male', 'female', 'other']),
            'skill_level' => fake()->randomElement(config('training.skill_levels')),
            'school' => null,
            'jersey_number' => (string) fake()->numberBetween(1, 99),
            'is_child' => false,
            'emergency_contact' => null,
            'token_spend_requires_approval' => true,
        ];
    }

    /** The profile created for every Player/Parent account so BR-022 needs no special case. */
    public function selfProfile(User $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_user_id' => $owner->id,
            'user_id' => $owner->id,
            'name' => $owner->name,
            'is_child' => false,
        ]);
    }

    public function child(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_child' => true,
            'birth_date' => fake()->dateTimeBetween('-17 years', '-6 years'),
            'school' => fake()->word().' School',
        ]);
    }
}
