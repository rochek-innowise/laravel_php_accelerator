<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * NFR-007. Fortify ships a limiter for login only; password reset had none, and it is the trainer
 * onboarding path — an unlimited POST there mails an unbounded number of reset links to any
 * address that exists.
 */
final class PasswordResetThrottleTest extends TestCase
{
    public function test_reset_link_requests_are_throttled(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'real@example.test']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('/forgot-password', ['email' => 'real@example.test'])->assertStatus(302);
        }

        $this->post('/forgot-password', ['email' => 'real@example.test'])->assertStatus(429);
    }

    public function test_password_update_attempts_are_throttled(): void
    {
        User::factory()->create(['email' => 'real@example.test']);

        $payload = [
            'token' => 'bogus',
            'email' => 'real@example.test',
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
        ];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('/reset-password', $payload)->assertStatus(302);
        }

        $this->post('/reset-password', $payload)->assertStatus(429);
    }

    /** Read-only routes stay exempt, so reloading the sign-in page cannot lock anyone out. */
    public function test_view_routes_are_not_throttled(): void
    {
        for ($attempt = 0; $attempt < 15; $attempt++) {
            $this->get('/login')->assertOk();
        }

        $this->get('/forgot-password')->assertOk();
    }
}
