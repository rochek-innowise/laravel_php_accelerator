---
{
  "id": "MEM-20260903-6bede1f2",
  "title": "Tests can pass while pinning nothing when the test case is already excluded by an outer filter",
  "type": "convention",
  "status": "active",
  "scope": [
    "application"
  ],
  "tags": [
    "testing",
    "race-conditions",
    "query-logic",
    "slice-c"
  ],
  "created": "2026-09-02",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "sources": [
    "tests/Feature/Approval/ExpirePurchaseApprovalsJobTest.php",
    "app/Jobs/ExpirePurchaseApprovalsJob.php"
  ],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": [
    {
      "path": "app/Jobs/ExpirePurchaseApprovalsJob.php",
      "sha256": "0657a7a4926522a7772d23f3467a841668f8680f1fcd1c76789b92a256de5ea0"
    },
    {
      "path": "tests/Feature/Approval/ExpirePurchaseApprovalsJobTest.php",
      "sha256": "0d50756b284ae8d12236127adc5108ca5c761ae295cedccaa9d3c71c7e71d332"
    }
  ]
}
---

# Tests Can Pass While Pinning Nothing

## Durable Context

The `ExpirePurchaseApprovalsJob` applies a conditional update on every row to guard against a race 
condition: if a guardian approves an approval in the same second as the expiry sweep runs, the 
per-row `where('status', ApprovalStatus::Pending)` guard ensures the row is not double-flipped to 
`expired` after it was already marked `approved`.

An early test built a row that matched the job's outer filter condition (`status = pending, 
expires_at < now()`) and asserted it was not mutated — but the test never actually set up the race. 
When the job's candidate query ran, it never even fetched the test row, because the row was 
constructed to *fail* an even earlier, outer `where('status', 'pending')` filter in a prior test 
phase. Deleting the per-row conditional-update guard would not fail this test; the outer filter 
already excluded the row entirely.

## The Lesson

Tests that exercise a guard must construct the test case so it *is* a candidate for the operation 
under guard. A test using a row the outer filter already excludes is pinning the outer filter, not 
the guard itself.

For race conditions specifically: simulate the actual race by hooking the operation at the moment 
between fetch and per-row update. In this case, listen for the candidate-row SELECT query, fire 
immediately, and mutate the row directly — then the per-row guard must still fire and prevent a 
double mutation:

```php
$raced = false;
DB::listen(function ($query) use ($approval, &$raced): void {
    if ($raced || ! str_contains($query->sql, 'select * from `purchase_approvals`')) {
        return;
    }
    $raced = true;
    // Approve the approval directly while the job is mid-sweep
    PurchaseApproval::query()->whereKey($approval->id)->update([
        'status' => ApprovalStatus::Approved,
    ]);
});

$this->job->handle();
// Per-row guard must still prevent a second flip to `expired`
$this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);
```

## Consequences

Review any test that asserts a guard was applied: confirm the test case is actually a candidate for 
the guarded operation. For fetch-then-update races, construct the race via a database listener 
rather than a mock or sleep.

## Verification

Commit ec1066d "Close the review findings on the family screen and approvals". Compare the two 
related tests in `tests/Feature/Approval/ExpirePurchaseApprovalsJobTest.php`: 
`a_row_already_resolved_before_the_sweeps_own_query_runs_is_never_a_candidate` (old, passing 
despite the guard being absent) vs. 
`a_row_resolved_between_the_candidate_fetch_and_the_per_row_update_is_not_double_flipped` 
(new, actually exercises the guard).
