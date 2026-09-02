---
{
  "id": "MEM-20260902-819a39fc",
  "title": "ApprovedPurchaseExecutor is called inside the transaction that performs the status transition; Epic-05 must decide its future placement",
  "type": "decision",
  "status": "active",
  "scope": [
    "application"
  ],
  "tags": [
    "architecture",
    "async",
    "payments",
    "slice-c",
    "epic-05-seam"
  ],
  "created": "2026-09-02",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "sources": [
    "app/Contracts/ApprovedPurchaseExecutor.php",
    "app/Actions/Approval/RespondToPurchaseApproval.php",
    "specs/architect-architecture.md"
  ],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-02",
  "valid_to": null,
  "source_digests": [
    {
      "path": "app/Actions/Approval/RespondToPurchaseApproval.php",
      "sha256": "ba75bb6d32a5e027ef894a34ea25988c760eaf10b3b0c6e74a23048e9332a7db"
    },
    {
      "path": "app/Contracts/ApprovedPurchaseExecutor.php",
      "sha256": "6732d14994f89613056495a6814d4e3e3c3848222c84582dacc87bc88475d44c"
    },
    {
      "path": "specs/architect-architecture.md",
      "sha256": "9ae52c4452e70a97dcb786cd2e6d77977cf44c50cf335a6f32ed9af107a94a93"
    }
  ]
}
---

# ApprovedPurchaseExecutor Placement: In-Transaction Now, Future Decision For Epic-05

## Decision

The `ApprovedPurchaseExecutor::execute()` method is called **inside** `DB::transaction()` 
immediately after the status update to `approved` completes. This is safe for `NullPurchaseExecutor` 
(which writes an audit log), but it is documented as a contract violation for any real payment call.

## Rationale

The current placement trades two failure modes:

**In-transaction (current, Slice C):**
- ✓ Atomicity: status and charge happen together, or neither happens.
- ✗ Holds the row lock for the duration of the payment network call.
- ✗ If the payment succeeds but the transaction's own `commit()` fails, the row's status is rolled 
  back to `pending` (not `approved`), while the payment stands. A retry will charge again, and the 
  per-row conditional guard cannot prevent it because the row never actually left `pending` in the 
  database.

**After-commit placement (deferred to Epic-05):**
- ✓ No lock hold; the transaction closes before the network call.
- ✓ Idempotency must be implemented by the executor itself (via payment provider reference, webhook 
  deduplication, or equivalent).
- ✗ If the after-commit call fails, the row shows `approved` but nothing was actually charged. 
  Without an execution-state column, the retry decision is opaque.

## Consequences

Slice C documents the contract but does not move the executor. Epic-05 must choose between:

1. **Outbox/execution-state pattern**: add an `execution_state` column (`pending`/`succeeded`/`failed`), 
   commit it atomically with the status, and call after-commit with retry logic.
2. **Idempotency at the executor level**: rely on the payment provider's deduplication (Stripe 
   idempotency keys, etc.) and move the call after-commit.
3. **Keep the in-transaction placement**: accept the lock hold and rely on provider-side retry 
   guarantees for the transaction-commit failure case.

This decision must be made before a real payment executor is implemented. Slice C's architecture 
does not force a choice; it only makes the trade-offs explicit.

## Verification

Contract documented in `app/Contracts/ApprovedPurchaseExecutor.php` docblock, pinned by two call 
sites that guard with conditional updates before calling: 
- `RequestPurchaseApproval::handle()` (token bypass path)
- `RespondToPurchaseApproval::handle()` (guardian approval path)

Test double (`NullPurchaseExecutor`) writes an audit log and nothing else, exercised via 
`PurchaseExecutorTest`.
