<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Contracts\ApprovedPurchaseExecutor;
use App\Enums\ApprovalStatus;
use App\Enums\PaymentType;
use App\Exceptions\PurchaseApprovalException;
use App\Models\PlayerProfile;
use App\Models\PurchaseApproval;
use App\Models\User;
use App\Notifications\PurchaseApprovalBypassed;
use App\Notifications\PurchaseApprovalRequested;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * FR-010 / BR-014. A profile-only child has no login to act with, so there is nobody who could
 * have initiated a purchase — that path throws rather than silently creating an orphaned request.
 *
 * The token bypass (BR-014) still writes a row, created already `approved` (Decision 7 in the
 * Slice C plan): no `pending` phase, never visible in the approval queue, but it still runs
 * through the same `ApprovedPurchaseExecutor` calling convention every other approved row uses,
 * and it still gets an audit trail.
 */
final class RequestPurchaseApproval
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ApprovedPurchaseExecutor $executor,
    ) {}

    public function handle(
        PlayerProfile $child,
        ?Model $approvable,
        int $amountCents,
        PaymentType $paymentType,
    ): PurchaseApproval {
        if ($child->user_id === null) {
            throw PurchaseApprovalException::forProfileOnlyChild($child);
        }

        $bypassesApproval = $paymentType === PaymentType::Token && ! $child->token_spend_requires_approval;

        $approval = DB::transaction(function () use ($child, $approvable, $amountCents, $paymentType, $bypassesApproval): PurchaseApproval {
            $requestedAt = now();

            $approval = new PurchaseApproval;
            $approval->forceFill([
                'player_profile_id' => $child->getKey(),
                'payment_type' => $paymentType,
                'amount_cents' => $amountCents,
                'status' => $bypassesApproval ? ApprovalStatus::Approved : ApprovalStatus::Pending,
                'requested_at' => $requestedAt,
                'responded_at' => $bypassesApproval ? $requestedAt : null,
                'expires_at' => $requestedAt->copy()->addHours(48),
            ]);

            if ($approvable !== null) {
                $approval->approvable()->associate($approvable);
            }

            $approval->save();

            if ($bypassesApproval) {
                $this->executor->execute($approval->fresh());
            }

            $this->auditLogger->log('purchase-approval.requested', $approval, [
                'payment_type' => $paymentType->value,
                'amount_cents' => $amountCents,
                'bypassed' => $bypassesApproval,
            ]);

            return $approval;
        });

        // After commit (AD-007): a rollback must never leave a sent notification describing a
        // request that never actually happened.
        DB::afterCommit(function () use ($child, $approval, $bypassesApproval): void {
            $child->guardians()->get()->each(
                fn (User $guardian) => $guardian->notify(
                    $bypassesApproval
                        ? new PurchaseApprovalBypassed($approval)
                        : new PurchaseApprovalRequested($approval)
                )
            );
        });

        return $approval;
    }
}
