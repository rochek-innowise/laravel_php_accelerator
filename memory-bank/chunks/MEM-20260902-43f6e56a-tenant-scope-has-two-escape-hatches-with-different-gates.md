---
{
  "id": "MEM-20260902-43f6e56a",
  "title": "Tenant scope has two escape hatches with different gates",
  "type": "convention",
  "status": "active",
  "scope": [
    "application"
  ],
  "tags": [
    "tenancy",
    "authorization",
    "slice-b"
  ],
  "created": "2026-09-02",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "sources": [
    "app/Support/Tenancy/TrainerContext.php",
    "app/Support/Tenancy/BelongsToTenant.php",
    "specs/architect-architecture.md"
  ],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-02",
  "valid_to": null,
  "source_digests": [
    {
      "path": "app/Support/Tenancy/BelongsToTenant.php",
      "sha256": "ac6101cbe9eec7d1c52eba480f9f21706e4f4f92d9e86350ba3d916bdfd0d407"
    },
    {
      "path": "app/Support/Tenancy/TrainerContext.php",
      "sha256": "fbda4247aa2c26a6de20443ac4190b4d56855a63a8760167cea98661791e2d48"
    },
    {
      "path": "specs/architect-architecture.md",
      "sha256": "895380c5bb928706ce588a9cefe0a8efb1fedf2bd830b8c3e9c6e52d63ecd673"
    }
  ]
}
---

# Tenant Scope Has Two Escape Hatches With Different Gates

## Durable Context

`TenantScope` is fail-closed, and there are **two** documented ways past it, not the single
Super-Admin-gated one AD-001 describes:

- `Model::withoutTenantScope()` throws unless the actor is a Super Admin. Admin inspection only.
- `TrainerContext::runAsSystem(Closure)` suppresses the scope with **no gate at all**, for paths
  that legitimately predate a tenant: ShareLink lookup by a guest, coach-status checks, the trainer
  switcher's membership query, and system jobs.

Separately, a relation keyed on an identity's own primary key bypasses the scope and is *not* an
escape hatch but an identity read — `User::coachProfile()`, `PlayerProfile::trainerAssociations()`.

## Consequences

`runAsSystem` is the call site to scrutinise in review: it is reachable without authentication, so
each use must be a path that genuinely has no organisation yet. Adding one to a trainer-facing read
would be a silent cross-organisation leak that no test currently names.

The rule for new code: a query that begins at a tenant-owned model (`TrainerPlayer::query()`,
`CoachProfile::query()`) is a tenant read and must stay scoped; a relation from an identity to its
own rows is an identity read. A trainer's roster is always the association joined through
`TrainerPlayer`, never `PlayerProfile::query()`.

## Verification

`tests/Feature/Tenancy/FailClosedScopeTest.php` asserts both gates; `IsolationMatrixTest` asserts the
roster rule. Review if a third escape appears, or if `runAsSystem` gains a call site on a
trainer-facing screen.
