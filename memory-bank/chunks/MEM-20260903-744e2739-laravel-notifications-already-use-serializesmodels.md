---
{
  "id": "MEM-20260903-744e2739",
  "title": "Laravel's base Notification class already uses SerializesModels; fat queue payloads are not a concern for notification classes",
  "type": "domain",
  "status": "active",
  "scope": [
    "application"
  ],
  "tags": [
    "security",
    "queue",
    "notifications",
    "false-positive",
    "slice-c"
  ],
  "created": "2026-09-02",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "sources": [
    "vendor/laravel/framework/src/Illuminate/Notifications/Notification.php",
    "app/Notifications/PurchaseApprovalRequested.php"
  ],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": [
    {
      "path": "app/Notifications/PurchaseApprovalRequested.php",
      "sha256": "b456a585d0493e6ae252721d5ef49d7de0978716aefc81193cce316100f59101"
    },
    {
      "path": "vendor/laravel/framework/src/Illuminate/Notifications/Notification.php",
      "sha256": "814d2f2adddfccd6d1f077ed967609bbb8e802e112ac5e4ebe07946406dba1ec"
    }
  ]
}
---

# Laravel's Base Notification Class Already Uses SerializesModels

## Durable Context

The security review flagged Slice C's five new queued notifications (holding model instances like 
`PurchaseApproval` and `PlayerProfile`) as potential fat-payload risks. The concern was that 
serializing a full model to the queue might leak sensitive data.

This is a false positive. Laravel's `Illuminate\Notifications\Notification` base class already 
uses the `SerializesModels` trait, which automatically converts model instances to their ids during 
serialization and re-hydrates them on deserialization. No raw model data reaches the queue.

## Verification

- Vendor file: `vendor/laravel/framework/src/Illuminate/Notifications/Notification.php` line 5–9 
  declares `use SerializesModels`.
- Test coverage: `tests/Feature/Notifications/PurchaseApprovalNotificationTest` reads the actual 
  queued `jobs.payload` JSON via `DB::table('jobs')` and verifies the model is stored as an id 
  reference, not a full record.

## Consequences

Do not re-raise this finding in future security reviews. Queued notifications are safe by design 
in Laravel.

Slice C confirmed this by test rather than by code inspection alone, so future notification 
implementations can rely on the same guarantee without re-auditing it.
