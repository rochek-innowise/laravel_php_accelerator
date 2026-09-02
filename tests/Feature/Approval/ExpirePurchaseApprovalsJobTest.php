<?php

declare(strict_types=1);

namespace Tests\Feature\Approval;

use App\Enums\ApprovalStatus;
use App\Jobs\ExpirePurchaseApprovalsJob;
use App\Models\PlayerProfile;
use App\Models\PurchaseApproval;
use App\Models\User;
use App\Notifications\PurchaseApprovalExpired;
use App\Services\AuditLogger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NFR-009 / BR-015. The sweep uses the same conditional-update guard as a manual response, so a
 * row a guardian resolves in the same tick as the sweep is never double-flipped — asserted here by
 * pre-resolving the row before the job runs, not by racing a sleep against it.
 */
final class ExpirePurchaseApprovalsJobTest extends TestCase
{
    #[Test]
    public function an_overdue_pending_row_is_expired_and_guardians_are_notified(): void
    {
        Notification::fake();

        $mother = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($mother)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->overdue()->create(['player_profile_id' => $child->id]);

        app(ExpirePurchaseApprovalsJob::class)->handle(app(AuditLogger::class));

        $fresh = $approval->fresh();
        $this->assertSame(ApprovalStatus::Expired, $fresh->status);
        $this->assertNotNull($fresh->responded_at);
        Notification::assertSentTo($mother, PurchaseApprovalExpired::class);
    }

    #[Test]
    public function a_row_not_yet_due_is_left_untouched(): void
    {
        Notification::fake();

        $mother = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($mother)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        app(ExpirePurchaseApprovalsJob::class)->handle(app(AuditLogger::class));

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
        Notification::assertNothingSent();
    }

    /**
     * A row resolved a moment before the sweep reaches it: the conditional update
     * (`where('status', 'pending')`) is what stops the job from ever seeing this as a candidate to
     * flip, so the guard is exercised via a genuinely resolved row, not a mocked query.
     */
    #[Test]
    public function a_row_already_resolved_before_the_sweep_runs_is_not_double_flipped(): void
    {
        Notification::fake();

        $mother = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($mother)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->overdue()->approved()->create(['player_profile_id' => $child->id]);
        $respondedAt = $approval->responded_at;

        app(ExpirePurchaseApprovalsJob::class)->handle(app(AuditLogger::class));

        $fresh = $approval->fresh();
        $this->assertSame(ApprovalStatus::Approved, $fresh->status);
        $this->assertSame($respondedAt->toDateTimeString(), $fresh->responded_at->toDateTimeString());
        Notification::assertNotSentTo($mother, PurchaseApprovalExpired::class);
    }

    #[Test]
    public function the_job_is_registered_on_the_schedule(): void
    {
        $registered = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => $event->description === ExpirePurchaseApprovalsJob::class);

        $this->assertTrue($registered, 'ExpirePurchaseApprovalsJob is not registered in routes/console.php.');
    }
}
