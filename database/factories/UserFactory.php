<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'name' => $firstName.' '.$lastName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Player,
            'status' => UserStatus::Active,
            'is_child_account' => false,
            'phone' => fake()->phoneNumber(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function superAdmin(): static
    {
        return $this->role(Role::SuperAdmin);
    }

    public function trainer(): static
    {
        return $this->role(Role::Trainer);
    }

    public function coach(): static
    {
        return $this->role(Role::Coach);
    }

    public function status(UserStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    /** A child login: role stays Player, the constraint flag is what gates abilities. */
    public function childAccount(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Player,
            'is_child_account' => true,
        ]);
    }
}
