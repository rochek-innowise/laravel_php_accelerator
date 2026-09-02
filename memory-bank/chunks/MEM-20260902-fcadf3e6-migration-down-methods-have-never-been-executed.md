---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-fcadf3e6",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/dynamic/findings/fcadf3e6-0767-40c9-bed7-8ccaf6bc8721.md",
      "sha256": "8373acaae70a8c68fa8f05a2cddef51aca08534f34eb74859f3f11bf0119eee1"
    }
  ],
  "sources": [
    "project-brain/dynamic/findings/fcadf3e6-0767-40c9-bed7-8ccaf6bc8721.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "Migration down() methods have never been executed",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# Migration down() methods have never been executed

Resolved. Six of the seven Slice A down() methods were executed and observed succeeding in the reported output; the seventh (player_guardians) was reversed separately once it had been applied to the development database. The defect the review found is fixed too: the drop-name migration restores its column as nullable rather than backfilling empty strings. A clean rebuild with seed data followed and produced the expected scenario, including the two-guardian child.
