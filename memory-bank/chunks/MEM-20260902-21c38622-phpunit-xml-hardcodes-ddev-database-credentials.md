---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-21c38622",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/dynamic/findings/21c38622-034f-433d-942a-2c19578ef06a.md",
      "sha256": "f2410405979e0200d67a7ead91fd303db84fdbc178d15e30e9e1e9fb5817ebe4"
    }
  ],
  "sources": [
    "project-brain/dynamic/findings/21c38622-034f-433d-942a-2c19578ef06a.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "phpunit.xml hardcodes DDEV database credentials",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# phpunit.xml hardcodes DDEV database credentials

Resolved, and the original premise was wrong. PHPUnit sets an <env> entry only when the variable is absent (no force="true" here), so CI overrides the DDEV values by exporting DB_*. Proved by running the suite with DB_DATABASE=nonexistent_db: it fails, meaning the environment wins. No separate testing env file is needed; phpunit.xml now documents this so the next reader does not re-raise it.
