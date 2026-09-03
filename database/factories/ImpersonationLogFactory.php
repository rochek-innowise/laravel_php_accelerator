<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImpersonationLog>
 */
class ImpersonationLogFactory extends Factory
{
    /**
     * @return array<model-property<ImpersonationLog>, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(1, 30));

        return [
            'admin_user_id' => User::factory()->superAdmin(),
            'target_user_id' => User::factory(),
            'started_at' => $startedAt,
            'ended_at' => null,
            'duration_seconds' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }

    /** A completed session, closed with a plausible duration. */
    public function ended(): static
    {
        return $this->state(function (array $attributes): array {
            $startedAt = $attributes['started_at'] ?? now()->subMinutes(10);
            $endedAt = $startedAt->copy()->addMinutes(fake()->numberBetween(1, 59));

            return [
                'ended_at' => $endedAt,
                'duration_seconds' => abs($endedAt->getTimestamp() - $startedAt->getTimestamp()),
            ];
        });
    }
}
