<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    public function test_an_active_user_can_log_in(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_records_the_last_login_timestamp(): void
    {
        $user = User::factory()->create(['last_login_at' => null]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->status(UserStatus::Inactive)->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Account deactivated. Contact support.']);

        $this->assertGuest();
    }

    public function test_a_deleted_user_cannot_log_in(): void
    {
        $user = User::factory()->status(UserStatus::Deleted)->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * NFR-007. Fortify applies its login limiter as `throttle:login` middleware, so an exhausted
     * limit is a 429 rather than a validation error — the request never reaches the pipeline, and
     * the deactivation message therefore cannot be probed without limit.
     */
    public function test_repeated_attempts_are_throttled(): void
    {
        $user = User::factory()->status(UserStatus::Inactive)->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
