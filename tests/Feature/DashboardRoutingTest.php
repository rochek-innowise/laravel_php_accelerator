<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** FR-004: every role lands on its own dashboard and is refused the others. */
final class DashboardRoutingTest extends TestCase
{
    /**
     * @return array<string, array{Role, string}>
     */
    public static function roleDashboards(): array
    {
        return [
            'super admin' => [Role::SuperAdmin, '/admin/users'],
            'trainer' => [Role::Trainer, '/trainer'],
            'coach' => [Role::Coach, '/coach'],
            'player' => [Role::Player, '/player'],
        ];
    }

    #[DataProvider('roleDashboards')]
    public function test_each_role_is_redirected_to_its_own_dashboard(Role $role, string $path): void
    {
        $user = User::factory()->role($role)->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect($path);
    }

    public function test_a_player_cannot_reach_the_trainer_dashboard(): void
    {
        $player = User::factory()->role(Role::Player)->create();

        $this->actingAs($player)->get('/trainer')->assertForbidden();
    }

    public function test_a_coach_cannot_reach_the_player_dashboard(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)->get('/player')->assertForbidden();
    }

    public function test_a_super_admin_may_reach_any_role_dashboard(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('/trainer')->assertOk();
        $this->actingAs($admin)->get('/coach')->assertOk();
    }

    /** Q-01.05a: verification gates actions, not the login itself. */
    public function test_an_unverified_user_reaches_their_profile_but_not_a_dashboard(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/email/verify');
    }
}
