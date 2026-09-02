<?php

declare(strict_types=1);

namespace Tests\Feature\Approval;

use App\Actions\Approval\RequestPurchaseApproval;
use App\Contracts\ApprovedPurchaseExecutor;
use App\Enums\ApprovalStatus;
use App\Enums\PaymentType;
use App\Exceptions\PurchaseApprovalException;
use App\Models\PlayerProfile;
use App\Models\User;
use App\Notifications\PurchaseApprovalBypassed;
use App\Notifications\PurchaseApprovalRequested;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-010 / BR-014. The two branches `RequestPurchaseApproval` can take, plus the guard that keeps
 * a profile-only child from ever becoming the subject of a request nobody could have made.
 */
final class RequestPurchaseApprovalTest extends TestCase
{
    #[Test]
    public function a_usd_request_creates_a_pending_row_and_notifies_every_guardian(): void
    {
        Notification::fake();

        $mother = User::factory()->create();
        $father = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()
            ->guardedBy($mother, relationship: 'mother')
            ->guardedBy($father, isPrimary: false, relationship: 'father')
            ->create(['user_id' => $childLogin->id]);

        $approval = app(RequestPurchaseApproval::class)->handle($child, null, 2500, PaymentType::Usd);

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame($child->getKey(), $approval->player_profile_id);
        $this->assertSame(2500, $approval->amount_cents);
        $this->assertNull($approval->responded_at);
        $this->assertSame(
            $approval->requested_at->copy()->addHours(48)->toDateTimeString(),
            $approval->expires_at->toDateTimeString(),
        );

        Notification::assertSentTo($mother, PurchaseApprovalRequested::class);
        Notification::assertSentTo($father, PurchaseApprovalRequested::class);
        Notification::assertNotSentTo($childLogin, PurchaseApprovalRequested::class);
    }

    #[Test]
    public function a_bypassed_token_request_is_created_already_approved_and_only_sends_the_bypass_notification(): void
    {
        Notification::fake();
        $spy = $this->spy(ApprovedPurchaseExecutor::class);

        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()
            ->guardedBy($guardian)
            ->create(['user_id' => $childLogin->id, 'token_spend_requires_approval' => false]);

        $approval = app(RequestPurchaseApproval::class)->handle($child, null, 300, PaymentType::Token);

        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertNotNull($approval->responded_at);
        $this->assertSame(
            $approval->requested_at->toDateTimeString(),
            $approval->responded_at->toDateTimeString(),
        );

        $spy->shouldHaveReceived('execute')->once();

        Notification::assertSentTo($guardian, PurchaseApprovalBypassed::class);
        Notification::assertNotSentTo($guardian, PurchaseApprovalRequested::class);
    }

    #[Test]
    public function a_token_request_still_requiring_approval_creates_a_pending_row_not_a_bypass(): void
    {
        Notification::fake();
        $spy = $this->spy(ApprovedPurchaseExecutor::class);

        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()
            ->guardedBy($guardian)
            ->create(['user_id' => $childLogin->id, 'token_spend_requires_approval' => true]);

        $approval = app(RequestPurchaseApproval::class)->handle($child, null, 300, PaymentType::Token);

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $spy->shouldNotHaveReceived('execute');
        Notification::assertSentTo($guardian, PurchaseApprovalRequested::class);
        Notification::assertNotSentTo($guardian, PurchaseApprovalBypassed::class);
    }

    #[Test]
    public function a_profile_only_child_cannot_request_a_purchase_approval(): void
    {
        $guardian = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create();

        $this->assertNull($child->user_id);

        $this->expectException(PurchaseApprovalException::class);

        app(RequestPurchaseApproval::class)->handle($child, null, 1000, PaymentType::Usd);
    }
}
