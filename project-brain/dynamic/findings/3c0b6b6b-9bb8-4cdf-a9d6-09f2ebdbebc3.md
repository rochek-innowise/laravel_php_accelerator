---
{
  "authority": "verified",
  "authorized_owners": [
    "local"
  ],
  "confidence": 1.0,
  "conflicts": [],
  "created_at": "2026-09-02T16:11:52.047305+00:00",
  "external_id": "TASK-001-SLICE-B-F1",
  "files": [],
  "goal": "Accepting a coach invitation overwrites the redeemer's role, demoting a Trainer or Super Admin",
  "id": "3c0b6b6b-9bb8-4cdf-a9d6-09f2ebdbebc3",
  "next_steps": [],
  "owner": "local",
  "privacy": "team",
  "progress": "Only Player and Coach may accept a coaching invitation; any other role is refused before the role column is touched, so a forwarded link can no longer demote a Super Admin or orphan an organisation.",
  "revision": 2,
  "schema_version": 1,
  "source_fingerprints": [
    {
      "path": "app/Actions/Trainer/AcceptCoachInvitation.php",
      "sha256": "8ae74ccd9bf68c4bba9e84cfee5cd1c7c8a1ed9004e5eb3db1a09b42820ece82"
    }
  ],
  "sources": [
    "app/Actions/Trainer/AcceptCoachInvitation.php"
  ],
  "status": "resolved",
  "superseded_by": null,
  "supersedes": [],
  "title": "Accepting a coach invitation overwrites the redeemer's role, demoting a Trainer or Super Admin",
  "transitions": [
    {
      "actor": "local",
      "at": "2026-09-02T16:11:52.047305+00:00",
      "from": null,
      "reason": "Finding created",
      "to": "open"
    },
    {
      "actor": "local",
      "at": "2026-09-02T16:25:29.686103+00:00",
      "from": "open",
      "reason": "Resolved",
      "to": "resolved"
    }
  ],
  "type": "finding",
  "updated_at": "2026-09-02T16:25:29.686316+00:00"
}
---

# Accepting a coach invitation overwrites the redeemer's role, demoting a Trainer or Super Admin

## Goal
Accepting a coach invitation overwrites the redeemer's role, demoting a Trainer or Super Admin

## Progress
Only Player and Coach may accept a coaching invitation; any other role is refused before the role column is touched, so a forwarded link can no longer demote a Super Admin or orphan an organisation.

## Next Steps
- None

## Files
- None

## Sources
- `app/Actions/Trainer/AcceptCoachInvitation.php`
