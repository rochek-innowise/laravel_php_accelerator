<?php

declare(strict_types=1);

namespace Tests\Feature\Join;

use App\Livewire\Join\RedeemShareLink;
use App\Models\ShareLink;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/join/{code}` is the only surface in the application that creates an account (AD-004), and a
 * player link is permanent and unlimited-use (BR-008). That combination is what makes each of
 * these worth pinning.
 */
final class JoinHardeningTest extends TestCase
{
    #[Test]
    public function registering_sends_the_verification_email(): void
    {
        Notification::fake();

        $this->register($this->playerLink(), 'dana@example.test');

        $user = User::where('email', 'dana@example.test')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[Test]
    public function the_email_is_stored_lowercased(): void
    {
        Notification::fake();

        $this->register($this->playerLink(), 'Dana@Example.Test');

        $this->assertDatabaseHas('users', ['email' => 'dana@example.test']);
    }

    /** A coach link must not enrol a seconds-old, self-asserted address. */
    #[Test]
    public function a_guest_following_a_coach_link_is_registered_but_not_enrolled(): void
    {
        Notification::fake();

        $trainer = TrainerProfile::factory()->create();
        $link = ShareLink::factory()->coach('coach@example.test')->create([
            'trainer_profile_id' => $trainer->id,
        ]);

        $this->register($link, 'coach@example.test');

        $this->assertDatabaseHas('users', ['email' => 'coach@example.test']);
        $this->assertDatabaseCount('coach_profiles', 0);
        $this->assertTrue($link->fresh()->is_active, 'The invitation must still be waiting.');
    }

    #[Test]
    public function repeated_registrations_from_one_address_are_throttled(): void
    {
        Notification::fake();
        $link = $this->playerLink();

        foreach (range(1, 5) as $i) {
            $this->register($link, "player{$i}@example.test");

            // Registration signs the new account in, and a signed-in visitor is refused the guest
            // branch outright — without this the loop would abort five times over and never reach
            // the limiter at all.
            auth()->logout();
        }

        Livewire::test(RedeemShareLink::class, ['code' => $link->code])
            ->set('first_name', 'Sixth')
            ->set('last_name', 'Account')
            ->set('email', 'player6@example.test')
            ->set('player_name', 'Sixth Account')
            ->set('password', 'correct-horse-battery-staple')
            ->set('password_confirmation', 'correct-horse-battery-staple')
            ->call('register')
            ->assertHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'player6@example.test']);

        RateLimiter::clear('join:register:127.0.0.1');
    }

    /**
     * A link spent before the form is submitted must leave no account behind — `/join/{code}` is
     * the only way back in (AD-004), so a stranded account is one the person can neither use nor
     * re-create.
     *
     * This exercises the guard, not the rollback: Livewire re-reads the link on submit, so the
     * component refuses before it writes anything. The narrower race it cannot reach — another
     * visitor spending the link between that re-read and the locked re-read inside the action — is
     * what the surrounding `DB::transaction()` covers, and it cannot be produced from one process.
     */
    #[Test]
    public function a_link_spent_before_submission_creates_no_account(): void
    {
        Notification::fake();
        $link = $this->playerLink();

        $component = Livewire::test(RedeemShareLink::class, ['code' => $link->code])
            ->set('first_name', 'Dana')
            ->set('last_name', 'Reyes')
            ->set('email', 'dana@example.test')
            ->set('player_name', 'Dana Reyes')
            ->set('password', 'correct-horse-battery-staple')
            ->set('password_confirmation', 'correct-horse-battery-staple');

        $link->forceFill(['is_active' => false])->save();

        $component->call('register')->assertStatus(410);

        $this->assertDatabaseMissing('users', ['email' => 'dana@example.test']);
        $this->assertDatabaseCount('player_profiles', 0);
    }

    protected function register(ShareLink $link, string $email): void
    {
        Livewire::test(RedeemShareLink::class, ['code' => $link->code])
            ->set('first_name', 'Dana')
            ->set('last_name', 'Reyes')
            ->set('email', $email)
            ->set('player_name', 'Dana Reyes')
            ->set('password', 'correct-horse-battery-staple')
            ->set('password_confirmation', 'correct-horse-battery-staple')
            ->call('register')
            ->assertHasNoErrors();

        // Asserted, not assumed: `assertHasNoErrors` passes for an aborted request too, which is
        // exactly how the throttle case above once passed while testing nothing.
        $this->assertDatabaseHas('users', ['email' => Str::lower($email)]);
    }

    protected function playerLink(): ShareLink
    {
        return ShareLink::factory()->create([
            'trainer_profile_id' => TrainerProfile::factory()->create()->id,
        ]);
    }
}
