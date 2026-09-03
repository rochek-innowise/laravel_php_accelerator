---
{
  "created": "2026-09-03",
  "id": "MEM-20260903-517a2aae",
  "last_verified": "2026-09-03",
  "review_after": "2027-09-03",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "app/Models/User.php",
      "sha256": "e39fbf3cd2704462ac60e5797e3af7018d0e6a25c2de5c8129973def25336973"
    },
    {
      "path": "project-brain/dynamic/findings/517a2aae-9228-463e-ad6a-d98411fe6296.md",
      "sha256": "b4162193b173d52917519166bba4847e061e3b2b212ae3788a3b9864c1f6989c"
    }
  ],
  "sources": [
    "app/Models/User.php",
    "project-brain/dynamic/findings/517a2aae-9228-463e-ad6a-d98411fe6296.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "User::coachProfile() is an unordered hasOne, so a re-hired coach resolves to their stale released row and loses their tenant",
  "type": "domain",
  "valid_from": "2026-09-03",
  "valid_to": null
}
---

# User::coachProfile() is an unordered hasOne, so a re-hired coach resolves to their stale released row and loses their tenant

Re-fingerprinted: Slice C extended User with the guardianship and trainable-profile cache reset. This finding's own subject is unchanged.

## Sources
- app/Models/User.php
