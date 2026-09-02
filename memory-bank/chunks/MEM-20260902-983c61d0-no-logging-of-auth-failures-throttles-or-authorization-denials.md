---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-983c61d0",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/983c61d0-473b-4893-8927-b3af31aa6c42.md",
      "sha256": "2bb09b96dce6aa063bfba84f41a4ddffc6b62c8805fd911702b8df3eb0e96d22"
    }
  ],
  "sources": [
    "project-brain/archive/finding/983c61d0-473b-4893-8927-b3af31aa6c42.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "No logging of auth failures, throttles or authorization denials",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# No logging of auth failures, throttles or authorization denials

Fixed in ff48b14: login, logout, failure, throttle, terminated session and denial are all audited. The attempted address is recorded; the submitted password never is.
