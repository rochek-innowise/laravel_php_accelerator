<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** FR-003 / Q-01.05a: verification gates actions, not login, and the link round trip works. */
final class EmailVerificationTest extends TestCase
{
    public function test_the_verification_expiry_is_configured_for_24_hours(): void
    {
        $this->assertSame(1440, config('auth.verification.expire'));
    }

    public function test_a_generated_verification_link_expires_24_hours_out(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            parse_str((string) parse_url($mail->actionUrl, PHP_URL_QUERY), $query);

            $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, (int) $query['expires'], 5);

            return true;
        });
    }

    public function test_a_user_verifies_their_email_through_the_signed_link(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unsigned_verification_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get("/email/verify/{$user->id}/".sha1($user->getEmailForVerification()))
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_verification_notice_can_resend_the_link(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/email/verify')->assertOk();
        $this->actingAs($user)->post('/email/verification-notification');

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
