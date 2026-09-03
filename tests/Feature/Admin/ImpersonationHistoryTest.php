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

    /**
     * Gap 9: the `(target_user_id, started_at)` index was documented as serving "every session
     * for one target" but no such query existed — this filter is that query.
     */
    public function test_filtering_by_target_email_shows_only_that_targets_sessions(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['email' => 'target@example.test']);
        $otherTarget = User::factory()->create(['email' => 'other@example.test']);

        ImpersonationLog::factory()->ended()->create(['admin_user_id' => $admin->id, 'target_user_id' => $target->id]);
        ImpersonationLog::factory()->ended()->create(['admin_user_id' => $admin->id, 'target_user_id' => $otherTarget->id]);

        Livewire::actingAs($admin)
            ->test(ImpersonationHistory::class)
            ->set('targetEmail', 'target@example.test')
            ->assertSee($target->name)
            ->assertDontSee($otherTarget->name);
    }

    public function test_an_email_matching_nobody_shows_no_sessions(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        ImpersonationLog::factory()->ended()->create(['admin_user_id' => $admin->id, 'target_user_id' => $target->id]);

        Livewire::actingAs($admin)
            ->test(ImpersonationHistory::class)
            ->set('targetEmail', 'nobody@example.test')
            ->assertDontSee($target->name)
            ->assertSee('No impersonation sessions recorded yet.');
    }
}
