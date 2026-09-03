---
{
  "created": "2026-09-03",
  "id": "MEM-20260903-3c0b6b6b",
  "last_verified": "2026-09-03",
  "review_after": "2027-09-03",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "app/Actions/Trainer/AcceptCoachInvitation.php",
      "sha256": "8ae74ccd9bf68c4bba9e84cfee5cd1c7c8a1ed9004e5eb3db1a09b42820ece82"
    },
    {
      "path": "project-brain/dynamic/findings/3c0b6b6b-9bb8-4cdf-a9d6-09f2ebdbebc3.md",
      "sha256": "e29a81ad323f9d9139c72958e4c43d61868b4ce2a2606dfc764fce7c52eaff2a"
    }
  ],
  "sources": [
    "app/Actions/Trainer/AcceptCoachInvitation.php",
    "project-brain/dynamic/findings/3c0b6b6b-9bb8-4cdf-a9d6-09f2ebdbebc3.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "Accepting a coach invitation overwrites the redeemer's role, demoting a Trainer or Super Admin",
  "type": "domain",
  "valid_from": "2026-09-03",
  "valid_to": null
}
---

# Accepting a coach invitation overwrites the redeemer's role, demoting a Trainer or Super Admin

Only Player and Coach may accept a coaching invitation; any other role is refused before the role column is touched, so a forwarded link can no longer demote a Super Admin or orphan an organisation.

## Sources
- app/Actions/Trainer/AcceptCoachInvitation.php
