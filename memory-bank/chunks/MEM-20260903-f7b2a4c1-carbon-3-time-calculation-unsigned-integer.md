---
{
  "id": "MEM-20260903-f7b2a4c1",
  "title": "Carbon 3 changed diffInSeconds() to signed and fractional, breaking unsigned integer columns",
  "type": "constraint",
  "status": "active",
  "scope": ["application"],
  "tags": ["carbon", "database", "migration", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["app/Actions/Admin/StopImpersonation.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# Carbon 3 Changed diffInSeconds() to Signed and Fractional

## Durable Context

In Carbon 2, `$a->diffInSeconds($b)` returned a positive integer. In Carbon 3, it returns a signed float. Writing the result directly into an `unsignedInteger` column throws a type or range error.

This affects any Carbon-2-era pattern carried into the codebase where the difference is being stored, not just displayed.

## Consequences

- Replace `$a->diffInSeconds($b)` with `abs($a->getTimestamp() - $b->getTimestamp())` when the result must be an unsigned integer.
- Compute `$a->getTimestamp() - $b->getTimestamp()` (signed, integer) first, then take `abs()` if you need unsigned; don't rely on `diffInSeconds()` to handle the sign.
- If fractional seconds are important, retrieve them separately and store in a float/decimal column.
- Code review: search for `.diffInSeconds(` writing to an integer column, especially `unsignedInteger` or any database operation.
- Test: when upgrading from Carbon 2 to 3, run the full test suite; type errors on `save()` or `update()` will expose the issue.

## Verification

From `app/Actions/Admin/StopImpersonation.php` at the `closeLog()` method:

```php
// getTimestamp() difference, not diffInSeconds(): Carbon 3 made the latter signed and
// fractional by default, which would write a negative, non-integer value into an
// unsigned integer column.
//
// Finding 7 (Slice D): clamped to the timeout ceiling, so this path and
// CloseStaleImpersonationLogsJob's always agree on what gets recorded for the same kind of
// session — a session that ran (or was merely abandoned) past 60 minutes is reported as
// exactly 60 minutes either way, never the raw elapsed wall-clock time. Without this, an
// idle tab whose next request lands 25 hours later took this path and recorded 90000
// where the job would have recorded 3600 for the identical session — and which one won
// was a race with the 15-minute scheduler. It also keeps
// impersonation-history.blade.php's `gmdate('H:i:s', $duration)` safe: that format wraps
// at 86400 seconds, so an unclamped value could render as a misleadingly small duration.
$elapsedSeconds = abs($endedAt->getTimestamp() - $startedAtCarbon->getTimestamp());
$timeoutCeilingSeconds = EnforceImpersonationTimeout::TIMEOUT_MINUTES * 60;

$log->forceFill([
    'ended_at' => $endedAt,
    'duration_seconds' => min($elapsedSeconds, $timeoutCeilingSeconds),
])->save();
```

The fix uses `getTimestamp()` (integer Unix timestamp) and takes `abs()` to ensure an unsigned result fits the schema. The clamped value (`min($elapsedSeconds, $timeoutCeilingSeconds)`) ensures consistency with the job-based cleanup and prevents overflow in display code.

The database schema defines `duration_seconds` as `unsignedInteger`; any other type constraint should be verified at upgrade time.
