<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Support\Authorization\ImpersonationGuardrail;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Slice D Decision 6's signed-off write guardrail, exercised through the real Gate::before hook
 * — a session carrying impersonator_id is set directly here, no full StartImpersonation flow
 * needed, since the guardrail's own contribution is what is under test.
 */
final class ImpersonationGuardrailTest extends TestCase
{
    /**
     * `session()` only populates the session store bound in the container; a Gate check made
     * directly (no HTTP request in flight) reads `request()->session()`, which only sees data
     * once the store is attached to the current Request the way StartSession would during a
     * real request cycle.
     */
    protected function impersonateInSession(int $adminId): void
    {
        $this->session(['impersonator_id' => $adminId]);
        $this->app['request']->setLaravelSession($this->app['session']->driver());
    }

    public function test_every_denied_ability_is_refused_while_impersonating(): void
    {
        $target = User::factory()->create();
        $this->actingAs($target);
        $this->impersonateInSession(User::factory()->superAdmin()->create()->id);

        foreach (ImpersonationGuardrail::DENIED as $ability) {
            Gate::define($ability, fn (User $user): bool => true);

            $this->assertFalse($target->can($ability), "Ability [{$ability}] was allowed while impersonating.");
        }
    }

    public function test_denied_abilities_are_allowed_again_once_impersonation_is_not_active(): void
    {
        $target = User::factory()->create();
        $this->actingAs($target);

        foreach (ImpersonationGuardrail::DENIED as $ability) {
            Gate::define($ability, fn (User $user): bool => true);

            $this->assertTrue($target->can($ability), "Ability [{$ability}] was denied outside impersonation.");
        }
    }

    /**
     * Isolates the guardrail from UserPolicy::delete()'s own (also-denying, for unrelated
     * reasons) verdict: a Super-Admin-flagged actor with impersonator_id also set would be
     * allowed to delete another user by UserPolicy::delete() alone (it only checks
     * "isSuperAdmin() && not self"), so this assertion would fail if the guardrail's
     * Gate::before hook were ever removed — the exact regression the plan calls out (this
     * scenario cannot occur through the real Start flow, since BR-016 forbids a Super Admin
     * ever being the impersonation target; it exists purely to isolate the guardrail).
     */
    public function test_the_guardrail_denies_deleting_a_user_even_when_the_policy_alone_would_allow_it(): void
    {
        $superAdminActor = User::factory()->superAdmin()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($superAdminActor);
        $this->impersonateInSession(User::factory()->superAdmin()->create()->id);

        $this->assertFalse(Gate::allows('delete', $otherUser));
    }

    public function test_put_user_password_is_refused_while_impersonating_and_succeeds_once_stopped(): void
    {
        $target = User::factory()->create();

        $this->actingAs($target);
        $this->session(['impersonator_id' => User::factory()->superAdmin()->create()->id]);

        $this->from('/user/password')->put('/user/password', [
            'current_password' => 'password',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ])->assertForbidden();

        $this->assertTrue(password_verify('password', $target->fresh()->password));

        // Once impersonation is no longer active, the same request succeeds identically to today.
        $this->app['session']->forget('impersonator_id');

        $this->from('/user/password')->put('/user/password', [
            'current_password' => 'password',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(password_verify('a-new-password-123', $target->fresh()->password));
    }
}
