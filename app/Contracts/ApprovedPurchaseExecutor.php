<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\PurchaseApproval;

/**
 * AD-006: epic boundaries are interfaces, and this is the only one in this epic. Bound to
 * `NullPurchaseExecutor` here and rebound to the Stripe/token implementation in Epic-05 — no other
 * stub, feature flag, or `class_exists` check may stand in for that epic.
 *
 * Contract an Epic-05 implementation must honour:
 *
 * - **Idempotent per approval id.** `execute()` may be called more than once for the same
 *   `$approval->getKey()` — not by this epic's own call sites, which each guard with a
 *   conditional status update before calling in (see below), but by anything Epic-05 adds on top
 *   (a retried job, a redelivered webhook, an at-least-once queue). A second call for an approval
 *   already charged must not charge it again.
 *
 * - **Currently invoked *inside* the transaction that performs the `pending` → `approved`
 *   transition** — see the call sites in `RequestPurchaseApproval::handle()` (the token-bypass
 *   path) and `RespondToPurchaseApproval::handle()` (the guardian-approval path), both inside
 *   `DB::transaction()`. That is safe today only because `NullPurchaseExecutor` does nothing. Once
 *   this becomes a real payment call (a network round trip), that placement is a bug in waiting on
 *   two fronts: the round trip holds the row lock for its duration, and a failure in the
 *   transaction's own commit *after* a successful charge would roll the status back to `pending`
 *   while the charge stands — a retry then charges twice, and the conditional-update guard cannot
 *   catch this because the row never actually left `pending`.
 *
 * - **Moving the call behind `DB::afterCommit()` is not a free fix and must not be made without a
 *   design decision.** That placement trades the double-charge failure above for its mirror image:
 *   a committed `approved` status with nothing actually charged if the after-commit call then
 *   fails, and nothing left in the schema to notice or retry it. Resolving that properly needs an
 *   execution-state column (or equivalent) the transaction can commit atomically with the status,
 *   which this epic's schema does not have.
 *
 * Epic-05 must choose between an outbox/execution-state approach and after-commit execution — with
 * the trade-off above in mind — before this interface backs a real payment call. See the Slice C
 * plan (`tasks/TASK-001/writing-plans-slice-c-plan.md`) and the review finding that raised this.
 * Nothing about the transaction placement changes here; this docblock only records the contract.
 */
interface ApprovedPurchaseExecutor
{
    public function execute(PurchaseApproval $approval): void;
}
