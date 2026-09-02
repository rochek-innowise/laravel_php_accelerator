<?php

declare(strict_types=1);

namespace App\Services\Approval;

use App\Contracts\ApprovedPurchaseExecutor;
use App\Models\PurchaseApproval;
use App\Services\AuditLogger;

/**
 * Epic-05 does not exist yet. This records that an approved purchase would have been executed —
 * an audit-log entry, nothing else — and never touches payment or tokens. Swapping in the real
 * implementation is a single rebind of `ApprovedPurchaseExecutor` in `AppServiceProvider`; nothing
 * in the approval domain changes.
 */
final class NullPurchaseExecutor implements ApprovedPurchaseExecutor
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(PurchaseApproval $approval): void
    {
        $this->auditLogger->log('purchase-approval.executed', $approval, [
            'player_profile_id' => $approval->player_profile_id,
            'payment_type' => $approval->payment_type->value,
            'amount_cents' => $approval->amount_cents,
        ]);
    }
}
