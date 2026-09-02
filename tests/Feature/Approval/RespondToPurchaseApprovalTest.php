<?php

declare(strict_types=1);

namespace Tests\Feature\Approval;

use App\Actions\Approval\RespondToPurchaseApproval;
use App\Contracts\ApprovedPurchaseExecutor;
use App\Enums\ApprovalStatus;
use App\Models\PlayerProfile;
use App\Models\PurchaseApproval;
use App\Models\User;
use App\Notifications\PurchaseApprovalResolved;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-010's correctness centre: the conditional-update guard is what stops a double-clicked
 * Approve, or a race with ExpirePurchaseApprovalsJob, from running the executor twice.
 */
final class RespondToPurchaseApprovalTest extends TestCase
{
    #[Test]
    public function approving_a_pending_row_calls_the_executor_exactly_once_even_when_invoked_twice(): void
    {
        Notification::fake();
        $spy = $this->spy(ApprovedPurchaseExecutor::class);

        [$guardian, $childLogin, $approval] = $this->pendingApproval();

        $action = app(RespondToPurchaseApproval::class);

        $first = $action->handle($approval, $guardian, ApprovalStatus::Approved);
        $second = $action->handle($approval->fresh(), $guardian, ApprovalStatus::Approved);

        $this->assertTrue($first);
        $this->assertFalse($second);
        $spy->shouldHaveReceived('execute')->once();
        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);
        Notification::assertSentToTimes($childLogin, PurchaseApprovalResolved::class, 1);
    }

    #[Test]
    public function denying_a_pending_row_never_calls_the_executor(): void
    {
        Notification::fake();
        $spy = $this->spy(ApprovedPurchaseExecutor::class);

        [$guardian, $childLogin, $approval] = $this->pendingApproval();

        $result = app(RespondToPurchaseApproval::class)->handle($approval, $guardian, ApprovalStatus::Denied, 'Not this month.');

        $this->assertTrue($result);
        $spy->shouldNotHaveReceived('execute');
        $this->assertSame(ApprovalStatus::Denied, $approval->fresh()->status);
        $this->assertSame('Not this month.', $approval->fresh()->parent_note);
        Notification::assertSentTo($childLogin, PurchaseApprovalResolved::class);
    }

    #[Test]
    public function responding_to_an_already_resolved_row_returns_false_and_changes_nothing(): void
    {
        Notification::fake();

        [$guardian, , $approval] = $this->pendingApproval();
        $approval->forceFill(['status' => ApprovalStatus::Denied, 'responded_at' => now(), 'parent_note' => 'already handled'])->save();

        $result = app(RespondToPurchaseApproval::class)->handle($approval, $guardian, ApprovalStatus::Approved);

        $this->assertFalse($result);
        $this->assertSame(ApprovalStatus::Denied, $approval->fresh()->status);
        $this->assertSame('already handled', $approval->fresh()->parent_note);
        Notification::assertNothingSent();
    }

    /** @return array{0: User, 1: User, 2: PurchaseApproval} */
    private function pendingApproval(): array
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()
            ->guardedBy($guardian)
            ->create(['user_id' => $childLogin->id]);

        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        return [$guardian, $childLogin, $approval];
    }
}
