<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Tests\TestCase;

/** NFR-011 / OWASP A09: the auth surface writes to the same audit trail as everything else. */
final class AuthAuditTest extends TestCase
{
    public function test_a_successful_login_is_audited(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
    }

    public function test_a_failed_login_is_audited_with_the_address_but_never_the_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'hunter2']);

        // sole(), not first(): a single attempt must leave exactly one row, or the trail is noise.
        $log = AuditLog::where('action', 'auth.failed')->sole();

        $this->assertSame($user->email, $log->metadata['email']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($log->metadata));
        $this->assertArrayNotHasKey('password', $log->metadata);
    }

    /**
     * With a custom login limiter configured, Fortify throttles through middleware and never fires
     * the `Lockout` event, so the throttled request itself is what gets audited.
     */
    public function test_a_throttled_request_is_audited(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $log = AuditLog::where('action', 'request.throttled')->firstOrFail();

        $this->assertSame('login', $log->metadata['path']);
    }

    public function test_a_logout_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.logout',
            'subject_id' => $user->id,
        ]);
    }

    /** A session cut short by EnsureAccountRemainsActive used to leave no trace at all. */
    public function test_a_terminated_session_is_audited(): void
    {
        $user = User::factory()->status(UserStatus::Inactive)->create();

        $this->actingAs($user)->get('/profile');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.session_terminated',
            'subject_id' => $user->id,
        ]);
    }

    public function test_an_authorization_denial_is_audited(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player)->get('/admin/users')->assertForbidden();

        $log = AuditLog::where('action', 'authorization.denied')->sole();

        $this->assertSame($player->id, $log->actor_user_id);
        $this->assertSame('admin/users', $log->metadata['path']);
    }
}
