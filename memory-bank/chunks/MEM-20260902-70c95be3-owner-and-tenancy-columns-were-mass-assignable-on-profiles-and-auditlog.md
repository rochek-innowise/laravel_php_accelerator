---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-70c95be3",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/70c95be3-5ac0-4ef5-93b1-9e91dc9f6e12.md",
      "sha256": "905c57dcc242014ea3985a91996462acc42af8f1db937fa46ad9561dc9be908e"
    }
  ],
  "sources": [
    "project-brain/archive/finding/70c95be3-5ac0-4ef5-93b1-9e91dc9f6e12.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "Owner and tenancy columns were mass-assignable on profiles and AuditLog",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# Owner and tenancy columns were mass-assignable on profiles and AuditLog

Fixed in bd189e9: owner columns out of the allow-lists; AuditLog guards every attribute and is written only via AuditLogger. A request-supplied owner_user_id would have let one account claim another family child profile, which NFR-010 puts at 0%.
