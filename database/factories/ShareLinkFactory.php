<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShareLinkType;
use App\Models\ShareLink;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShareLink>
 */
class ShareLinkFactory extends Factory
{
    /**
     * @return array<model-property<ShareLink>, mixed>
     */
    public function definition(): array
    {
        $trainer = TrainerProfile::factory();

        return [
            'code' => ShareLink::mintCode(),
            'type' => ShareLinkType::Player,
            'trainer_profile_id' => $trainer,
            'created_by_user_id' => User::factory()->trainer(),
            'target_email' => null,
            'expires_at' => null,
            'max_uses' => null,
            'uses_count' => 0,
            'is_active' => true,
        ];
    }

    public function coach(?string $targetEmail = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ShareLinkType::Coach,
            'target_email' => $targetEmail ?? fake()->unique()->safeEmail(),
            'expires_at' => now()->addDays(ShareLinkType::Coach->ttlInDays() ?? 7),
            'max_uses' => ShareLinkType::Coach->maxUses(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'max_uses' => 1,
            'uses_count' => 1,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
