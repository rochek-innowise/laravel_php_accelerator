<?php

declare(strict_types=1);

namespace Tests\Feature\Impersonation;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\ImpersonationLog;
use App\Models\User;
use Tests\TestCase;

/** FR-012's passive 60-minute expiry: the only place a running impersonation is force-stopped by a live request. */
final class EnforceImpersonationTimeoutTest extends TestCase
{
    protected function startImpersonation(User $admin, User $target): void
    {
        $this->actingAs($admin)->post(route('admin.impersonate.start', $target));
    }

    protected function backdateSessionStart(int $minutesAgo): void
    {
        $this->app['session']->put('impersonation_started_at', now()->subMinutes($minutesAgo)->toISOString());
    }

    public function test_a_request_past_60_minutes_is_force_stopped_and_redirected(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->startImpersonation($admin, $target);
        $this->backdateSessionStart(61);

        $this->get('/dashboard')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Impersonation session expired after 60 minutes.');

        $this->assertSame($admin->id, auth()->id());
        $this->assertNull(session('impersonator_id'));

        $log = ImpersonationLog::query()->where('target_user_id', $target->id)->sole();
        $this->assertNotNull($log->ended_at);
    }

    /**
     * Finding 7: this path (a live request arriving long after the timeout) must record the same
     * duration CloseStaleImpersonationLogsJob would have for the identical session — the 60-minute
     * ceiling, not the raw elapsed wall-clock time. An unclamped value here would also wrap past
     * midnight in impersonation-history.blade.php's `gmdate('H:i:s', $duration)`.
     */
    public function test_a_stale_session_via_the_middleware_path_clamps_duration_to_the_timeout_ceiling(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->startImpersonation($admin, $target);
        $this->backdateSessionStart(25 * 60);

        $this->get('/dashboard');

        $log = ImpersonationLog::query()->where('target_user_id', $target->id)->sole();
        $this->assertSame(3600, $log->duration_seconds);
    }

    public function test_a_request_at_59_minutes_passes_through_untouched(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->startImpersonation($admin, $target);
        $this->backdateSessionStart(59);

        $this->get('/dashboard')->assertRedirect(route($target->role->dashboardRoute()));

        $this->assertSame($target->id, auth()->id());
        $this->assertNotNull(session('impersonator_id'));

        $log = ImpersonationLog::query()->where('target_user_id', $target->id)->sole();
        $this->assertNull($log->ended_at);
    }

    public function test_a_timed_out_request_fails_closed_to_login_if_the_admin_was_deactivated(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->startImpersonation($admin, $target);
        $this->backdateSessionStart(61);

        $admin->forceFill(['status' => UserStatus::Inactive])->save();

        $this->get('/dashboard')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_request_with_no_active_impersonation_is_unaffected(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player)
            ->get('/dashboard')
            ->assertRedirect(route($player->role->dashboardRoute()));
    }

    /**
     * Finding 6 (BR-016 continuous enforcement): a second Super Admin promoting the live
     * impersonated target mid-session (e.g. via EditUserForm) must not silently turn the
     * impersonated session into a Super Admin session. This is checked on every request, well
     * inside the 60-minute timeout window.
     */
    public function test_promoting_the_impersonated_target_to_super_admin_force_stops_the_session(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->startImpersonation($admin, $target);

        $target->forceFill(['role' => Role::SuperAdmin])->save();

        $this->get('/dashboard')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Impersonation ended: the account you were viewing became a Super Admin.');

        $this->assertSame($admin->id, auth()->id());
        $this->assertNull(session('impersonator_id'));

        $log = ImpersonationLog::query()->where('target_user_id', $target->id)->sole();
        $this->assertNotNull($log->ended_at);
    }
}
