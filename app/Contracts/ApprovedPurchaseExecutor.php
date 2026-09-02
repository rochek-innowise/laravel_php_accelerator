<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\PurchaseApproval;

/**
 * AD-006: epic boundaries are interfaces, and this is the only one in this epic. Bound to
 * `NullPurchaseExecutor` here and rebound to the Stripe/token implementation in Epic-05 — no other
 * stub, feature flag, or `class_exists` check may stand in for that epic.
 */
interface ApprovedPurchaseExecutor
{
    public function execute(PurchaseApproval $approval): void;
}
