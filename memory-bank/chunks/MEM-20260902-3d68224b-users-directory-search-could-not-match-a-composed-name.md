---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-3d68224b",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/3d68224b-07b9-4e30-b86c-e6a330ca95e6.md",
      "sha256": "a0567b7ba3ed575a15e467129402635d0aa4e62d9f93bccc4ddc02f06948f01c"
    }
  ],
  "sources": [
    "project-brain/archive/finding/3d68224b-07b9-4e30-b86c-e6a330ca95e6.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "Users directory search could not match a composed name",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# Users directory search could not match a composed name

Fixed in 27c2eea: CONCAT_WS matched as one string, wildcards escaped. Probe before the fix: "Zinaida Petrenko" returned 0 rows and "%" returned every row.
