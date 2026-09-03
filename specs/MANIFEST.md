# Project: Sports Training Platform

A multi-tenant Laravel platform where independent trainers run their training organizations: players and parents join a trainer through an invitation link, families manage child profiles and approve their purchases, coaches deliver sessions under exactly one trainer, and a Super Admin oversees the platform with impersonation and GDPR tooling. Epic-01 builds the identity, authorization and tenancy backbone every later epic depends on.

## Specs Index

| File | Purpose | Depends On | Last Updated |
|------|---------|------------|--------------|
| architect-architecture.md | System design, layering, tenancy, architecture decisions | - | 2026-09-03 (Slice D added) |
| api-designer-spec.md | Endpoints, schemas, authentication | architect-architecture | not created — no public API in Epic-01 |
| frontend-design-spec.md | Pages, components, state management | architect-architecture | not created |
| docs-generator-implementation.md | Build process, deployment, tooling | - | not created |

## Key Decisions

- **Tenancy**: single-database, `trainer_profile_id` + a **fail-closed** global scope resolved from session. No tenancy package — one user legitimately spans tenants and there are no subdomains.
- **Identity**: `User` (credential) and `PlayerProfile` (trainable person) are separate tables. "Parent" is emergent from *guarding* a child, not a role — guardianship is the `player_guardians` relation so a child can have both parents (AD-019).
- **Authorization**: `Role` enum + Policies/Gates. No permissions package — four compile-time roles. Child constraints live in one `ChildAbilities::DENIED` array.
- **Auth scaffolding**: Fortify + Livewire installed directly (starter kits are `laravel new` templates, not installable here). Fortify registration is **disabled**; the only registration surface is `/join/{code}`.
- **Epic boundaries**: exactly one interface, `ApprovedPurchaseExecutor`, replaced by Epic-05. No other stubs.
- **Admin UI**: hand-rolled Livewire for Epic-01; Filament reconsidered at Epic-07.
- **Tenancy escapes**: two, not one — `Model::withoutTenantScope()` (Super-Admin gated) and
  `TrainerContext::runAsSystem()` (ungated, for paths that legitimately predate a tenant, such as a
  guest redeeming an invitation). See the Slice B section of the architecture spec.
- **Identity relations bypass the scope**: a relation keyed on an identity's own id is an identity
  read (`User::coachProfile()`, `PlayerProfile::trainerAssociations()`); a query starting at a
  tenant-owned model is a tenant read. A roster is always the former joined through `TrainerPlayer`.
- **Player ShareLink**: one active code per organisation; regenerating deactivates the previous one,
  so revocation is real. BR-008 makes a link unlimited in uses, not immortal as a code.
- **Real-time**: none. Database notifications + `wire:poll`; Reverb reconsidered at Epic-02.
- **Runtime**: a queue worker and the scheduler are required processes, not optional ops.
- **Account status**: enforced on every request, not only at login — a deactivated session ends on
  its next request (AD-015). Slice D's deactivate action needs no session bookkeeping of its own.
- **Mass assignment**: privilege and ownership columns are never in a `#[Fillable]` allow-list;
  actions set them via `forceFill` or the relationship (AD-016).
- **Trainer invitation**: links to the password-request form, carrying no token — no TTL to expire
  and nothing sensitive in the queue payload (AD-017).
- **Purchase approvals**: guardian-approved purchases flow through `PurchaseApproval`, a 48-hour
  expiring request state machine reachable via the child's owner profile, not tenant-scoped (AD-021).
  Approval transitions use conditional updates to guard idempotency, deferred to Epic-05 (AD-022).
- **Child login optionality**: a child profile may carry its own login with email and password,
  created in the same transaction as the profile with `is_child_account = true`, verified at
  creation (AD-023). A child login is denied everything on the deny list and sees only its own
  approvals on `/approvals`.
- **Scheduler is functional**: the 48-hour approval expiry (NFR-009) is exercised only if the
  scheduler runs every 15 minutes. Without it, pending approvals sit forever — no API fallback,
  no cached state. Listed here because overlooking it would be a silent runaway.

## Tech Stack

PHP 8.4 · Laravel 13 · MariaDB 11.8 · DDEV (nginx-fpm) · Blade + Livewire 4 + Alpine · Vite + Tailwind · PHPUnit 12 against MariaDB · Larastan level 5

**Not installed yet, despite earlier plans:** `livewire/flux` (Slice A uses plain accessible Blade;
revisit when a component needs more than a form and a table) and `intervention/image` (arrives with
the profile-photo work, which is deferred to the file-storage effort).

## Roadmap Context

Epic-01 (this work) blocks Epic-02 Events, Epic-03 CRM, Epic-04 Content, Epic-05 Payments, Epic-06 Marketing, Epic-07 Super Admin Tools. Epic-08 (camps) is referenced by an in-scope Epic-01 item that has no acceptance criteria and is currently deferred.

---

*This manifest is updated by the architect, api-designer, and frontend-design skills.*
*See `../spec-desc.md` for specification structure guidelines.*
