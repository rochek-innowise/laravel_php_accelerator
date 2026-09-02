---
{
  "authority": "verified",
  "authorized_owners": [
    "local"
  ],
  "confidence": 1.0,
  "conflicts": [],
  "created_at": "2026-09-02T16:12:04.894587+00:00",
  "external_id": "TASK-001-SLICE-B-F10",
  "files": [],
  "goal": "InviteCoach::resend() deactivates the existing link before work that can throw, destroying it with no replacement",
  "id": "a35ad9a0-11cd-4cd6-a4bc-d5aa44e5ac20",
  "next_steps": [],
  "owner": "local",
  "privacy": "team",
  "progress": "resend() mints the replacement first and retires the old link only once that succeeded, so a resend that cannot issue a replacement no longer destroys the trainer's only pending invitation.",
  "revision": 2,
  "schema_version": 1,
  "source_fingerprints": [
    {
      "path": "app/Actions/Trainer/InviteCoach.php",
      "sha256": "f78cc4a1db5e690b580429700e50a59287d51d1a73a5be18bff9287f16154a17"
    }
  ],
  "sources": [
    "app/Actions/Trainer/InviteCoach.php"
  ],
  "status": "resolved",
  "superseded_by": null,
  "supersedes": [],
  "title": "InviteCoach::resend() deactivates the existing link before work that can throw, destroying it with no replacement",
  "transitions": [
    {
      "actor": "local",
      "at": "2026-09-02T16:12:04.894587+00:00",
      "from": null,
      "reason": "Finding created",
      "to": "open"
    },
    {
      "actor": "local",
      "at": "2026-09-02T16:25:53.210898+00:00",
      "from": "open",
      "reason": "Resolved",
      "to": "resolved"
    }
  ],
  "type": "finding",
  "updated_at": "2026-09-02T16:25:53.211116+00:00"
}
---

# InviteCoach::resend() deactivates the existing link before work that can throw, destroying it with no replacement

## Goal
InviteCoach::resend() deactivates the existing link before work that can throw, destroying it with no replacement

## Progress
resend() mints the replacement first and retires the old link only once that succeeded, so a resend that cannot issue a replacement no longer destroys the trainer's only pending invitation.

## Next Steps
- None

## Files
- None

## Sources
- `app/Actions/Trainer/InviteCoach.php`
