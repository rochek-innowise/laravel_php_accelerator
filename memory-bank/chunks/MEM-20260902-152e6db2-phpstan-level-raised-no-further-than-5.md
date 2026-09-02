---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-152e6db2",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/dynamic/findings/152e6db2-07cb-4048-b0ea-3df3c87d3451.md",
      "sha256": "abfa8d093cbc1746744c207c20b9986d87004950a54e4311d5b695519f37c8a6"
    }
  ],
  "sources": [
    "project-brain/dynamic/findings/152e6db2-07cb-4048-b0ea-3df3c87d3451.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "PHPStan level raised no further than 5",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# PHPStan level raised no further than 5

Resolved as a recorded decision rather than an open gap. Level 6 was 34 errors; three were ours (an untyped Attribute on User::name(), now fixed) and the remaining 31 are all the identical unresolved TComponent generic on Livewire::actingAs()->test() — the package's typing, not this codebase. Level 5 therefore stays, clean and with no baseline, and phpstan.neon records the measurement so the next attempt starts from evidence.
