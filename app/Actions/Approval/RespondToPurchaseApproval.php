<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Contracts\ApprovedPurchaseExecutor;
use App\Enums\ApprovalStatus;
use App\Models\PurchaseApproval;
use App\Models\User;
use App\Notifications\PurchaseApprovalResolved;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * FR-010's correctness centre, mirroring `RedeemShareLink`'s idempotency pattern: the transition is
 * a conditional update guarded by the row's current status, and the executor runs only if that
 * update actually affected a row. A double-clicked Approve — or a race with
 * `ExpirePurchaseApprovalsJob` — must not run the executor twice, which becomes a double charge
 * the moment Epic-05 lands.
 *
 * Responding to an already-resolved row is not an error: it returns `false` and changes nothing,
 * the same way a second click on an already-spent ShareLink is a no-op rather than an exception.
 */
final class RespondToPurchaseApproval
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ApprovedPurchaseExecutor $executor,
    ) {}

    public function handle(
        PurchaseApproval $approval,
        User $guardian,
        ApprovalStatus $decision,
        ?string $note = null,
    ): bool {
        $affected = DB::transaction(function () use ($approval, $guardian, $decision, $note): bool {
            $affected = PurchaseApproval::query()
                ->whereKey($approval->getKey())
                ->where('status', ApprovalStatus::Pending)
                ->update([
                    'status' => $decision,
                    'responded_at' => now(),
                    'parent_note' => $note,
                ]);

            if ($affected !== 1) {
                return false;
            }

            if ($decision === ApprovalStatus::Approved) {
                $this->executor->execute($approval->fresh());
            }

            $this->auditLogger->log('purchase-approval.'.$decision->value, $approval->fresh(), [
                'guardian_user_id' => $guardian->getKey(),
            ]);

            return true;
        });

        if ($affected) {
            // After commit (AD-007): the child's notification must not describe a transition that
            // a rollback undid.
            DB::afterCommit(
                fn () => $approval->playerProfile?->user?->notify(new PurchaseApprovalResolved($approval->fresh()))
            );
        }

        return $affected;
    }
}
