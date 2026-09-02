<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ApprovalStatus;
use App\Models\PurchaseApproval;
use App\Models\User;
use App\Notifications\PurchaseApprovalExpired;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * NFR-009 / BR-015. Unlike `DeactivateRosterAssociations`, this needs no `TrainerContext` at all:
 * `PurchaseApproval` is owner-scoped, not tenant-owned (AD-001's third data class), so there is no
 * tenant-owned query here to fail closed on.
 *
 * Each row is flipped with the same conditional-update guard `RespondToPurchaseApproval` uses
 * (`where('status', 'pending')`), so a row a guardian approves or denies in the same tick as the
 * sweep is never double-flipped to expired.
 */
final class ExpirePurchaseApprovalsJob implements ShouldQueue
{
    use Queueable;

    public function handle(AuditLogger $auditLogger): void
    {
        PurchaseApproval::query()
            ->where('status', ApprovalStatus::Pending)
            ->where('expires_at', '<', now())
            ->chunkById(200, function (Collection $approvals) use ($auditLogger): void {
                foreach ($approvals as $approval) {
                    $this->expire($approval, $auditLogger);
                }
            });
    }

    private function expire(PurchaseApproval $approval, AuditLogger $auditLogger): void
    {
        $affected = DB::transaction(function () use ($approval, $auditLogger): bool {
            $affected = PurchaseApproval::query()
                ->whereKey($approval->getKey())
                ->where('status', ApprovalStatus::Pending)
                ->update([
                    'status' => ApprovalStatus::Expired,
                    'responded_at' => now(),
                ]);

            if ($affected !== 1) {
                return false;
            }

            $auditLogger->log('purchase-approval.expired', $approval->fresh());

            return true;
        });

        if (! $affected) {
            return;
        }

        // After commit (AD-007): the guardians' notification must not describe an expiry a
        // rollback undid.
        DB::afterCommit(function () use ($approval): void {
            $approval->playerProfile
                ?->guardians()
                ->get()
                ->each(fn (User $guardian) => $guardian->notify(new PurchaseApprovalExpired($approval->fresh())));
        });
    }
}
