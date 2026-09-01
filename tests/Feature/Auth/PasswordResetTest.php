<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** FR-002: the full reset round trip, which is also the trainer onboarding path. */
final class PasswordResetTest extends TestCase
{
    public function test_a_user_resets_their_password_end_to_end(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $mail) use (&$token): bool {
            $token = $mail->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Str0ngPassw0rd!', $user->fresh()->password));

        $this->post('/login', ['email' => $user->email, 'password' => 'Str0ngPassw0rd!'])
            ->assertRedirect('/dashboard');
    }

    public function test_a_deactivated_account_receives_no_reset_link(): void
    {
        Notification::fake();
        $inactive = User::factory()->status(UserStatus::Inactive)->create();

        $response = $this->post('/forgot-password', ['email' => $inactive->email]);

        Notification::assertNotSentTo($inactive, ResetPassword::class);

        // The response must stay identical to the active case, or refusing to mail becomes an
        // account-status oracle for anyone probing addresses.
        $response->assertSessionHasNoErrors();
        $this->assertNotNull(session('status'));
    }

    public function test_a_stale_token_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
