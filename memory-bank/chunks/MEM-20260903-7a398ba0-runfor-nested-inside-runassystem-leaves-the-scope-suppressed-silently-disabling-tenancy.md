---
{
  "created": "2026-09-03",
  "id": "MEM-20260903-7a398ba0",
  "last_verified": "2026-09-03",
  "review_after": "2027-09-03",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "app/Support/Tenancy/TrainerContext.php",
      "sha256": "a335dc2b127c87afbf81df942ae5c74f6b9aeba8dc7db985d479c167742d8857"
    },
    {
      "path": "project-brain/dynamic/findings/7a398ba0-134d-4207-abb6-2c8d0ce2dac1.md",
      "sha256": "2f836db730a3481a518ae79e146f65d7a5f36f5b2d81c77b8975f6435864e0b4"
    }
  ],
  "sources": [
    "app/Support/Tenancy/TrainerContext.php",
    "project-brain/dynamic/findings/7a398ba0-134d-4207-abb6-2c8d0ce2dac1.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "runFor nested inside runAsSystem leaves the scope suppressed, silently disabling tenancy",
  "type": "domain",
  "valid_from": "2026-09-03",
  "valid_to": null
}
---

# runFor nested inside runAsSystem leaves the scope suppressed, silently disabling tenancy

runFor clears suppression for its duration and restores it after, so a runFor nested inside a runAsSystem is scoped rather than silently reading across every organisation.

## Sources
- app/Support/Tenancy/TrainerContext.php
