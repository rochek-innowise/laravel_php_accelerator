<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrainerProfile>
 */
class TrainerProfileFactory extends Factory
{
    /**
     * @return array<model-property<TrainerProfile>, mixed>
     */
    public function definition(): array
    {
        $businessName = fake()->company();

        return [
            'user_id' => User::factory()->trainer(),
            'business_name' => $businessName,
            'slug' => Str::slug($businessName).'-'.fake()->unique()->numberBetween(1, 99999),
            'address' => fake()->address(),
            'website' => fake()->url(),
            'description' => fake()->sentence(),
            'logo_path' => null,
            'primary_color' => fake()->hexColor(),
        ];
    }
}
