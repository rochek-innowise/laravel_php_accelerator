<?php

declare(strict_types=1);

namespace Tests\Feature\Family;

use App\Contracts\ApprovedPurchaseExecutor;
use App\Enums\ApprovalStatus;
use App\Livewire\Family\PendingApprovals;
use App\Models\PlayerProfile;
use App\Models\PurchaseApproval;
use App\Models\User;
use App\Notifications\PurchaseApprovalResolved;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-010/FR-011. One component, two audiences: a guardian gets working Approve/Deny buttons, a
 * child login sees only its own rows, read-only.
 */
final class PendingApprovalsTest extends TestCase
{
    #[Test]
    public function a_guardian_sees_every_guarded_childs_requests_with_working_buttons(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id, 'name' => 'Riley']);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id, 'amount_cents' => 1500]);

        $other = User::factory()->create();
        $otherChildLogin = User::factory()->childAccount()->create();
        $otherChild = PlayerProfile::factory()->child()->guardedBy($other)->create(['user_id' => $otherChildLogin->id, 'name' => 'NotMine']);
        PurchaseApproval::factory()->create(['player_profile_id' => $otherChild->id]);

        Livewire::actingAs($guardian)
            ->test(PendingApprovals::class)
            ->assertSee('Riley')
            ->assertDontSee('NotMine')
            ->assertSeeHtml('wire:click="approve('.$approval->id.')"')
            ->assertSeeHtml('wire:click="deny('.$approval->id.')"');
    }

    #[Test]
    public function a_child_login_sees_only_its_own_rows_with_no_buttons(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id, 'name' => 'Riley']);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        Livewire::actingAs($childLogin)
            ->test(PendingApprovals::class)
            ->assertSee('Riley')
            ->assertDontSeeHtml('wire:click="approve('.$approval->id.')"')
            ->assertDontSeeHtml('wire:click="deny('.$approval->id.')"');
    }

    #[Test]
    public function approving_from_the_ui_transitions_the_row_calls_the_executor_and_notifies_the_child(): void
    {
        Notification::fake();
        $spy = $this->spy(ApprovedPurchaseExecutor::class);

        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        Livewire::actingAs($guardian)
            ->test(PendingApprovals::class)
            ->call('approve', $approval->id)
            ->assertHasNoErrors();

        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);
        $spy->shouldHaveReceived('execute')->once();
        Notification::assertSentTo($childLogin, PurchaseApprovalResolved::class);
    }

    #[Test]
    public function denying_from_the_ui_transitions_the_row_without_calling_the_executor(): void
    {
        $spy = $this->spy(ApprovedPurchaseExecutor::class);

        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        Livewire::actingAs($guardian)->test(PendingApprovals::class)->call('deny', $approval->id);

        $this->assertSame(ApprovalStatus::Denied, $approval->fresh()->status);
        $spy->shouldNotHaveReceived('execute');
    }

    #[Test]
    public function a_child_login_cannot_call_approve_or_deny_directly(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        Livewire::actingAs($childLogin)->test(PendingApprovals::class)->call('approve', $approval->id)->assertForbidden();

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    #[Test]
    public function a_non_guardian_cannot_respond_to_someone_elses_approval(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)->test(PendingApprovals::class)->call('approve', $approval->id)->assertForbidden();

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }
}
