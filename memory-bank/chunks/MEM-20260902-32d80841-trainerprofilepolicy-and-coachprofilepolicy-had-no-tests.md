---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-32d80841",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/32d80841-7723-4012-9a6d-f361779bfbef.md",
      "sha256": "a45935332407bfe2106afae7590c2235f49d0ecaca6a1cf9a1e88722a75cb5f0"
    }
  ],
  "sources": [
    "project-brain/archive/finding/32d80841-7723-4012-9a6d-f361779bfbef.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "TrainerProfilePolicy and CoachProfilePolicy had no tests",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# TrainerProfilePolicy and CoachProfilePolicy had no tests

Fixed in 61b8c85: both covered, including the organisation boundary CoachProfilePolicy::employs draws, which Slice B replaces with TrainerContext.
