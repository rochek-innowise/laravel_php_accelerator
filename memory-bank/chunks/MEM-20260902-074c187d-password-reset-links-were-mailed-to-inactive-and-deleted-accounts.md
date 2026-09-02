---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-074c187d",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/074c187d-2423-4bc6-bccc-a043234aab53.md",
      "sha256": "3b79a8242a99b982efd7fd562996b98bf187912280e576996cb5cac9cbf5b434"
    }
  ],
  "sources": [
    "project-brain/archive/finding/074c187d-2423-4bc6-bccc-a043234aab53.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "Password reset links were mailed to inactive and deleted accounts",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# Password reset links were mailed to inactive and deleted accounts

Fixed in ff48b14: sendPasswordResetNotification returns early for non-active accounts. The broker still reports success, so no account-status oracle appears.
