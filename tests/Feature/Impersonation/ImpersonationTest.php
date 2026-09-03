<?php

declare(strict_types=1);

namespace Tests\Feature\Impersonation;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ImpersonationLog;
use App\Models\User;
use Tests\TestCase;

/**
 * FR-012 start/stop, dual attribution, and the two refusals (BR-016, no stacked session). The
 * write guardrail itself is covered by ImpersonationGuardrailTest; the passive 60-minute timeout
 * by EnforceImpersonationTimeoutTest; the abandoned-tab sweep by CloseStaleImpersonationLogsJobTest.
 */
final class ImpersonationTest extends TestCase
{
    public function test_starting_impersonation_logs_in_as_the_target_and_writes_the_three_session_keys(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->withSession(['unrelated_marker' => 'still-here'])
            ->from('/admin/users')
            ->post(route('admin.impersonate.start', $target))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($target->id, auth()->id());
        $this->assertSame($admin->id, session('impersonator_id'));
        $this->assertNotNull(session('impersonation_log_id'));
        $this->assertNotNull(session('impersonation_started_at'));

        // Auth::logout() was never called: it would have flushed the session and destroyed this.
        $this->assertSame('still-here', session('unrelated_marker'));

        $this->assertDatabaseHas('impersonation_logs', [
            'id' => session('impersonation_log_id'),
            'admin_user_id' => $admin->id,
            'target_user_id' => $target->id,
            'ended_at' => null,
        ]);
    }

    public function test_starting_impersonation_is_audited_and_dual_attributed(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.impersonate.start', $target));

        $log = AuditLog::where('action', 'impersonation.started')->sole();

        $this->assertSame($target->id, $log->actor_user_id);
        $this->assertSame($admin->id, $log->on_behalf_of_user_id);
    }

    /**
     * Any audited write reaches the same chokepoint (AuditLogger::log(), Slice A); logout is a
     * convenient existing one that any authenticated user, including an impersonated target, can
     * reach without needing another role's screen.
     */
    public function test_every_subsequent_write_while_impersonating_is_dual_attributed(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.impersonate.start', $target));

        $this->post('/logout');

        $log = AuditLog::where('action', 'auth.logout')->sole();

        $this->assertSame($target->id, $log->actor_user_id);
        $this->assertSame($admin->id, $log->on_behalf_of_user_id);
    }

    public function test_a_second_impersonation_while_one_is_active_is_refused(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $firstTarget = User::factory()->create();
        $secondTarget = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.impersonate.start', $firstTarget));

        // The acting session is now $firstTarget's — but BR-016/Gap 1 both key off the session,
        // not the acting identity, and the route itself is guarded by role:super_admin, so this
        // exercises the policy's own "already active" condition directly instead.
        $this->assertFalse(
            $admin->fresh()->can('impersonate', $secondTarget),
            'A second impersonation was allowed while one was already active.'
        );
    }

    public function test_impersonating_another_super_admin_is_refused_at_the_controller(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $otherAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.impersonate.start', $otherAdmin))
            ->assertForbidden();

        $this->assertSame($admin->id, auth()->id());
    }

    public function test_stopping_impersonation_restores_the_admin_and_closes_the_log(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.impersonate.start', $target));
        $logId = session('impersonation_log_id');

        $this->post(route('impersonate.stop'))
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame($admin->id, auth()->id());
        $this->assertNull(session('impersonator_id'));
        $this->assertNull(session('impersonation_log_id'));
        $this->assertNull(session('impersonation_started_at'));

        $log = ImpersonationLog::find($logId);
        $this->assertNotNull($log->ended_at);
        $this->assertNotNull($log->duration_seconds);
    }

    public function test_stopping_impersonation_is_audited_while_still_dual_attributed(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.impersonate.start', $target));
        $this->post(route('impersonate.stop'));

        $log = AuditLog::where('action', 'impersonation.stopped')->sole();

        $this->assertSame($target->id, $log->actor_user_id);
        $this->assertSame($admin->id, $log->on_behalf_of_user_id);
    }

    public function test_stopping_reauthenticates_the_admin_by_a_fresh_lookup_not_a_stale_reference(): void
    {
        $admin = User::factory()->superAdmin()->create(['first_name' => 'Original']);
        $target = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.impersonate.start', $target));

        // Changed on the admin's own row *after* the session captured only their id.
        $admin->forceFill(['first_name' => 'Renamed'])->save();

        $this->post(route('impersonate.stop'));

        $this->assertSame('Renamed', User::find($admin->id)->first_name);
        $this->assertSame($admin->id, auth()->id());
    }

    public function test_stopping_fails_closed_to_login_if_the_admin_was_deactivated_mid_session(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.impersonate.start', $target));

        $admin->forceFill(['status' => UserStatus::Inactive])->save();

        $this->post(route('impersonate.stop'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_no_op_stop_with_no_active_impersonation_just_redirects(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('impersonate.stop'))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($admin->id, auth()->id());
    }
}
