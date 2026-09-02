---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-2843363c",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/2843363c-84df-462f-8e26-8230aa604756.md",
      "sha256": "e86dd26be03ff9da1c04da044d9ee14524e84cdb7be09b76b2ffcd03f9387aca"
    }
  ],
  "sources": [
    "project-brain/archive/finding/2843363c-84df-462f-8e26-8230aa604756.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "Raw password-reset token persisted in the queue payload",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# Raw password-reset token persisted in the queue payload

Fixed in 61b8c85, then superseded by 7f9a20d which removed the token from the invitation entirely. The database queue driver persists job payloads, so a plaintext token would have sat at rest.
