# Project: Sports Training Platform

A multi-tenant Laravel platform where independent trainers run their training organizations: players and parents join a trainer through an invitation link, families manage child profiles and approve their purchases, coaches deliver sessions under exactly one trainer, and a Super Admin oversees the platform with impersonation and GDPR tooling. Epic-01 builds the identity, authorization and tenancy backbone every later epic depends on.

## Specs Index

| File | Purpose | Depends On | Last Updated |
|------|---------|------------|--------------|
| architect-architecture.md | System design, layering, tenancy, architecture decisions | - | 2026-09-01 |
| api-designer-spec.md | Endpoints, schemas, authentication | architect-architecture | not created — no public API in Epic-01 |
| frontend-design-spec.md | Pages, components, state management | architect-architecture | not created |
| docs-generator-implementation.md | Build process, deployment, tooling | - | not created |

## Key Decisions

- **Tenancy**: single-database, `trainer_profile_id` + a **fail-closed** global scope resolved from session. No tenancy package — one user legitimately spans tenants and there are no subdomains.
- **Identity**: `User` (credential) and `PlayerProfile` (trainable person) are separate tables. "Parent" is emergent from owning several profiles, not a role.
- **Authorization**: `Role` enum + Policies/Gates. No permissions package — four compile-time roles. Child constraints live in one `ChildAbilities::DENIED` array.
- **Auth scaffolding**: official Livewire starter kit on Fortify. Fortify registration is **disabled**; the only registration surface is `/join/{code}`.
- **Epic boundaries**: exactly one interface, `ApprovedPurchaseExecutor`, replaced by Epic-05. No other stubs.
- **Admin UI**: hand-rolled Livewire for Epic-01; Filament reconsidered at Epic-07.
- **Real-time**: none. Database notifications + `wire:poll`; Reverb reconsidered at Epic-02.
- **Runtime**: a queue worker and the scheduler are required processes, not optional ops.

## Tech Stack

PHP 8.4 · Laravel 13 · MariaDB 11.8 · DDEV (nginx-fpm) · Blade + Livewire + Alpine · Vite + Tailwind + Flux UI · PHPUnit 12 · `intervention/image` for uploads

## Roadmap Context

Epic-01 (this work) blocks Epic-02 Events, Epic-03 CRM, Epic-04 Content, Epic-05 Payments, Epic-06 Marketing, Epic-07 Super Admin Tools. Epic-08 (camps) is referenced by an in-scope Epic-01 item that has no acceptance criteria and is currently deferred.

---

*This manifest is updated by the architect, api-designer, and frontend-design skills.*
*See `../spec-desc.md` for specification structure guidelines.*
