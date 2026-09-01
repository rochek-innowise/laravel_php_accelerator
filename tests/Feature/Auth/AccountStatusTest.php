<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Tests\TestCase;

/**
 * FR-017 / FR-018 for sessions that were already open when the account was deactivated. Checking
 * the status only in the login pipeline left a deactivated user with full access until the session
 * expired — days, with the intended rolling lifetime, or indefinitely via remember-me.
 */
final class AccountStatusTest extends TestCase
{
    public function test_a_session_deactivated_mid_flight_is_terminated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')->assertOk();

        $user->forceFill(['status' => UserStatus::Inactive])->save();

        $this->get('/profile')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => UserStatus::DEACTIVATED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_deleted_account_is_locked_out_mid_session(): void
    {
        $user = User::factory()->status(UserStatus::Deleted)->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /** The remember-me cookie the browser still holds must die with the session. */
    public function test_the_remember_token_is_cycled_on_lockout(): void
    {
        $user = User::factory()->status(UserStatus::Inactive)->create();
        $before = $user->remember_token;

        $this->actingAs($user)->get('/profile');

        $this->assertNotSame($before, $user->fresh()->remember_token);
    }

    /** The guard covers Fortify's own authenticated routes, not just the ones in web.php. */
    public function test_a_deactivated_user_cannot_reach_fortify_profile_endpoints(): void
    {
        $user = User::factory()->status(UserStatus::Inactive)->create(['first_name' => 'Original']);

        $this->actingAs($user)->put('/user/profile-information', [
            'first_name' => 'Injected',
            'last_name' => 'Name',
        ])->assertRedirect(route('login'));

        $this->assertSame('Original', $user->fresh()->first_name);
    }

    public function test_an_active_user_is_unaffected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_guest_passes_through_without_a_redirect_loop(): void
    {
        $this->get('/login')->assertOk();
    }
}
