<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentType;
use App\Models\PlayerProfile;
use App\Models\PurchaseApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseApproval>
 */
class PurchaseApprovalFactory extends Factory
{
    /**
     * @return array<model-property<PurchaseApproval>, mixed>
     */
    public function definition(): array
    {
        $requestedAt = now();

        return [
            'player_profile_id' => PlayerProfile::factory(),
            'payment_type' => PaymentType::Usd,
            'amount_cents' => fake()->numberBetween(500, 20000),
            'status' => ApprovalStatus::Pending,
            'requested_at' => $requestedAt,
            'responded_at' => null,
            'expires_at' => $requestedAt->copy()->addHours(48),
            'parent_note' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Approved,
            'responded_at' => now(),
        ]);
    }

    public function denied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Denied,
            'responded_at' => now(),
        ]);
    }

    /** Past its expiry, but still pending — the shape the sweep is looking for. */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function token(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_type' => PaymentType::Token,
        ]);
    }
}
