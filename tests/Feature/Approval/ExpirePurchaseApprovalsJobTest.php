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
use Illuminate\Support\Facades\DB;
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
     * A row already resolved before the sweep's own candidate query runs is never a candidate at
     * all: `where('status', 'pending')` in `handle()` excludes it before `chunkById` ever fetches
     * it, so this pins the *outer* query's filter, not the per-row guard — deleting the per-row
     * conditional update in `expire()` would not fail this test, only the race below would.
     */
    #[Test]
    public function a_row_already_resolved_before_the_sweeps_own_query_runs_is_never_a_candidate(): void
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

    /**
     * The genuine race: a row that *is* a pending, overdue candidate when `handle()`'s own
     * `chunkById` query fetches it, then gets resolved by a guardian in the gap between that fetch
     * and `expire()`'s per-row conditional update. Simulated by hooking the sweep's own
     * candidate-row `SELECT` and flipping the row directly underneath it, the moment that query
     * fires — the same shape as the fetch-then-mutate race `RespondToPurchaseApprovalTest` exercises
     * against its own conditional update, adapted to a query listener because this race sits inside
     * one `handle()` call rather than two.
     *
     * Deleting the inner `where('status', ApprovalStatus::Pending)` guard inside `expire()`'s
     * per-row update makes this test fail (the row is overwritten to `expired`); the previous test
     * above does not catch that regression at all.
     */
    #[Test]
    public function a_row_resolved_between_the_candidate_fetch_and_the_per_row_update_is_not_double_flipped(): void
    {
        Notification::fake();

        $mother = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($mother)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->overdue()->create(['player_profile_id' => $child->id]);

        $raced = false;
        $respondedAt = null;

        DB::listen(function ($query) use ($approval, &$raced, &$respondedAt): void {
            if ($raced || ! str_contains($query->sql, 'select * from `purchase_approvals`')) {
                return;
            }

            $raced = true;
            $respondedAt = now();

            // Bypasses the model and the job entirely: a guardian's own approval landing in the
            // window between the sweep's fetch (just above) and its per-row update (still to come).
            PurchaseApproval::query()->whereKey($approval->getKey())->update([
                'status' => ApprovalStatus::Approved,
                'responded_at' => $respondedAt,
            ]);
        });

        app(ExpirePurchaseApprovalsJob::class)->handle(app(AuditLogger::class));

        $this->assertTrue($raced, 'The sweep never issued its candidate-row query — the race was never set up.');

        $fresh = $approval->fresh();
        $this->assertSame(ApprovalStatus::Approved, $fresh->status);
        $this->assertSame($respondedAt->toDateTimeString(), $fresh->responded_at->toDateTimeString());
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_job_is_registered_on_the_schedule(): void
    {
        $registered = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => $event->description === ExpirePurchaseApprovalsJob::class);

        $this->assertTrue($registered, 'ExpirePurchaseApprovalsJob is not registered in routes/console.php.');
    }
}
