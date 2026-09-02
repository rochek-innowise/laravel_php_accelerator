<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TrainerPlayerStatus;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainerPlayer>
 */
class TrainerPlayerFactory extends Factory
{
    /**
     * @return array<model-property<TrainerPlayer>, mixed>
     */
    public function definition(): array
    {
        return [
            'trainer_profile_id' => TrainerProfile::factory(),
            'player_profile_id' => PlayerProfile::factory(),
            'share_link_id' => null,
            'connected_at' => now(),
            'status' => TrainerPlayerStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TrainerPlayerStatus::Inactive,
        ]);
    }
}
