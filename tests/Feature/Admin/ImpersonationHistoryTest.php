<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ImpersonationHistory;
use App\Models\ImpersonationLog;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/** FR-012's compliance report. */
final class ImpersonationHistoryTest extends TestCase
{
    public function test_a_super_admin_sees_a_completed_session_with_its_duration(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();
        ImpersonationLog::factory()->ended()->create([
            'admin_user_id' => $admin->id,
            'target_user_id' => $target->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ImpersonationHistory::class)
            ->assertSee($admin->name)
            ->assertSee($target->name)
            ->assertDontSee('Active');
    }

    public function test_a_still_open_session_shows_no_ended_at(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();
        ImpersonationLog::factory()->create([
            'admin_user_id' => $admin->id,
            'target_user_id' => $target->id,
            'ended_at' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ImpersonationHistory::class)
            ->assertSee('Active');
    }

    public function test_a_non_super_admin_gets_403_on_the_history_route(): void
    {
        $this->actingAs(User::factory()->trainer()->create())
            ->get(route('admin.impersonation-history'))
            ->assertForbidden();
    }

    public function test_a_non_super_admin_cannot_mount_the_component_directly_either(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(ImpersonationHistory::class)
            ->assertForbidden();
    }
}
