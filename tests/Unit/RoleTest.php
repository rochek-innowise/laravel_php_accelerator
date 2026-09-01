<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Role;
use App\Enums\UserStatus;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function test_every_role_has_a_label_and_a_dashboard_route(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertNotSame('', $role->label());
            $this->assertNotSame('', $role->dashboardRoute());
        }
    }

    public function test_dashboard_routes_are_distinct_per_role(): void
    {
        $routes = array_map(fn (Role $role): string => $role->dashboardRoute(), Role::cases());

        $this->assertSame($routes, array_unique($routes));
    }

    public function test_only_an_active_status_may_log_in(): void
    {
        $this->assertTrue(UserStatus::Active->canLogIn());
        $this->assertFalse(UserStatus::Inactive->canLogIn());
        $this->assertFalse(UserStatus::Deleted->canLogIn());
    }
}
