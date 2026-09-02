---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-088fbcdd",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/088fbcdd-7a3a-48aa-9911-d7a2a4a49a0f.md",
      "sha256": "ffc205c98613553174b523bcfdd3292a2215fc7b90efb7c7d5e6682f3d0db8c8"
    }
  ],
  "sources": [
    "project-brain/archive/finding/088fbcdd-7a3a-48aa-9911-d7a2a4a49a0f.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "Account status enforced only at login, not per request",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# Account status enforced only at login, not per request

Fixed in 27c2eea: EnsureAccountRemainsActive appended to the web group so Fortify and Livewire routes are covered too. Verified by removing the middleware — 4 of 6 AccountStatusTest cases fail without it. Recorded as AD-015.
