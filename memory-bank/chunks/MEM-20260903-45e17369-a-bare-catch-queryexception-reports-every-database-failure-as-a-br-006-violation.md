---
{
  "created": "2026-09-03",
  "id": "MEM-20260903-45e17369",
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
      "path": "project-brain/dynamic/findings/45e17369-ca37-4b61-ae13-22f73c1cff3b.md",
      "sha256": "d3674e2aab340195507ef81264013fa658fac403340e35e4df7ee775872fdb18"
    }
  ],
  "sources": [
    "app/Actions/Trainer/AcceptCoachInvitation.php",
    "project-brain/dynamic/findings/45e17369-ca37-4b61-ae13-22f73c1cff3b.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "A bare catch(QueryException) reports every database failure as a BR-006 violation",
  "type": "domain",
  "valid_from": "2026-09-03",
  "valid_to": null
}
---

# A bare catch(QueryException) reports every database failure as a BR-006 violation

Only SQLSTATE 23000 / error 1062 is treated as a BR-006 refusal; deadlocks and lock-wait timeouts — both plausible here, since the method holds two row locks — now surface as faults instead of a plausible-looking field error.

## Sources
- app/Actions/Trainer/AcceptCoachInvitation.php
