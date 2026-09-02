---
description: Implementation plan for Epic-01 Slice B — tenancy, ShareLinks, associations, coach invitations, and the two switchers.
---

# Epic-01 Slice B — Implementation Plan

**Task**: TASK-001
**Scope**: Slice B only — `BelongsToTenant`/`TenantScope`/`TrainerContext`, ShareLinks with locked
redemption, player↔trainer associations, coach invitations with the BR-006 database constraint, and
both context switchers. Slices C and D keep their own plans.

**Depends on**: Slice A, complete and green — 140 tests, Pint clean, PHPStan level 5 clean.

## Goal

A player or parent redeems a trainer's ShareLink at `/join/{code}`, lands in that trainer's context,
and switches between organisations and family members from the navigation. A trainer invites a coach
by email through a single-use 7-day link, and the database itself refuses a second active trainer for
that coach. Every tenant-owned query is scoped by default and fails closed, so a missing context
yields an empty list rather than another organisation's data.

## Existing Context

| File | Current state | Impact in Slice B |
|---|---|---|
| `app/Models/CoachProfile.php` | Tenant-owned, carries `trainer_profile_id`, but **no scope**; comment reads `TODO(coder): apply BelongsToTenant in Slice B` | Gains the trait; the TODO is discharged |
| `database/migrations/…_create_coach_profiles_table.php` | `status` string default `invited`, index `(trainer_profile_id, status)`; comment defers the BR-006 generated column to Slice B | New migration adds the generated column + unique index; the existing file is **not** edited |
| `app/Policies/CoachProfilePolicy.php` | `viewAny()` carries `TODO(slice-b): scope the listing to the current tenant` | Tenancy branch filled in — the branch is already first, so nothing is retrofitted |
| `app/Policies/*` | Tenant branch is a documented no-op stub (AD-005 ordering fixed in Slice A) | Same — fill in, do not reorder |
| `routes/web.php` | Comment: *"the only registration surface is /join/{code}, in Slice B"* | `/join/{code}` lands here, outside the `auth` group |
| `app/Models/PlayerProfile.php` | Identity, never scoped; guardianship via `player_guardians` | Untouched schema; gains `trainerAssociations()` |
| `app/Models/User.php` | `guardedPlayerProfiles()`, `playerProfile()`, `isParent()` | Gains `trainableProfiles()` — self profile + guarded children, the checklist source |
| `app/Support/Authorization/ChildAbilities.php` | `trainer.associate` and `trainer-association.change` already denied | The child block on redemption is **already enforced** by the Gate hook; Slice B only needs the parent notification, which is Slice C |
| `bootstrap/app.php` | Aliases `role`; appends `EnsureAccountRemainsActive` to `web` | Gains the `tenant` alias for `EnsureTrainerContext` |
| `tests/TestCase.php` | `RefreshDatabase` + `withoutVite()` globally | Unchanged; tenancy tests set context explicitly |
| `app/Services/AuditLogger.php` | `log(action, subject, metadata)`, `forceFill`, records `on_behalf_of_user_id` | Reused verbatim for the six new audited actions |
| `app/Actions/Trainer/CreateTrainerAccount.php` | Transaction + `DB::afterCommit()` notification + audit row | The pattern every Slice B action copies (AD-007) |

## Assumptions

Recorded rather than blocking. Each is cheap to reverse; none changes the schema.

| Open item | Assumed | Reversal cost |
|---|---|---|
| **G-08** — what the trainer switcher may reveal | Business name and logo only. No counts, no badges, no aggregated notifications. Tested by asserting the switcher query touches `trainer_players` and `trainer_profiles` and nothing else | Add columns to one query |
| **G-11** — coach transfer between trainers | Explicit release: trainer A sets the coach row to `inactive` (history retained), which frees the generated column so trainer B's invitation can be accepted. No transfer UI in Slice B beyond deactivation | A new action; the schema already permits it |
| Coach invitation to an **existing** account | Permitted when the account is a Coach with no active row, or a Player with no profiles yet; otherwise a field-level error. Redeeming never changes an existing user's role silently | One branch in `AcceptCoachInvitation` |
| Player ShareLink per trainer | One active static link per trainer, regenerable; regenerating deactivates the previous code so an old link stops working (BR-008 says unlimited *uses*, not immortal codes) | Drop the deactivate step |
| Context after redemption | The newly joined trainer becomes the active context, matching *"redirect to trainer's events"* | One line in the action's caller |

## Proposed Design

### Tenancy core (`app/Support/Tenancy/`)

- **`TrainerContext`** — a singleton bound in `AppServiceProvider::register()`, holding a nullable
  `TrainerProfile`. API: `set(?TrainerProfile)`, `get(): ?TrainerProfile`, `id(): ?int`,
  `has(): bool`, `runFor(TrainerProfile $tenant, Closure $work): mixed` (sets, runs, restores the
  previous value in a `finally`), and `withoutScope(Closure $work): mixed`.
- **`TenantScope`** — a global scope. With a tenant: `where($model->getTable().'.trainer_profile_id', $id)`.
  Without one: `whereRaw('0 = 1')` — **fail-closed** (AD-001). Never `return` early.
- **`BelongsToTenant`** — trait. `bootBelongsToTenant()` adds the scope and a `creating` hook filling
  `trainer_profile_id` from the context when the attribute is unset; throws
  `TenantContextMissingException` when neither is available, so a context-less write is loud even
  though a context-less read is silently empty. Provides `trainerProfile()` and a
  `scopeWithoutTenantScope()` gated on `auth()->user()?->isSuperAdmin()`.
- **`TenantContextMissingException`** — `RuntimeException`; rendered as 500, never caught broadly.

### Middleware

**`EnsureTrainerContext`** (alias `tenant`), applied to the authenticated `verified` group.
Resolution order, evaluated on every request (never trusting the session alone):

1. **Trainer** → own `TrainerProfile`. Immutable, no switcher.
2. **Coach** → the tenant of the one `active` `CoachProfile`. Immutable, no switcher. An `invited`
   or `inactive` coach resolves to no tenant.
3. **Player/Parent** → `session('trainer_context_id')`, **re-validated** against the live
   association set (`TrainerPlayer` for any of the user's trainable profiles, status `active`). An
   association revoked mid-session stops resolving on the next request.
4. No valid session value but associations exist → the first association by `connected_at`.
5. No associations at all → no tenant. A player with none sees an empty-state dashboard, not a 500.
6. **Super Admin** → no tenant. The explicit read-only inspect tenant is Slice D.

### Schema

Three migrations, all additive; none edits a Slice A file.

- **`create_share_links_table`** — `code` unique (see entropy note), `type` (`player`/`coach`),
  `trainer_profile_id` constrained, `created_by_user_id` constrained, `target_email` nullable,
  `expires_at` nullable, `max_uses` nullable, `uses_count` default 0, `is_active` default true,
  timestamps. Index `(trainer_profile_id, type, is_active)`.
- **`create_trainer_players_table`** — `trainer_profile_id`, `player_profile_id`, `share_link_id`
  nullable `nullOnDelete`, `connected_at`, `status` (`active`/`inactive`), `deleted_at`, timestamps.
  Unique `(trainer_profile_id, player_profile_id, deleted_at)` — including `deleted_at` is what makes
  the constraint hold "among non-deleted rows" on MariaDB, where NULLs do not collide, so a removed
  association can be re-created later (FR-009).
- **`add_active_coach_constraint_to_coach_profiles`** — raw DDL, MariaDB-specific:
  ```sql
  ALTER TABLE coach_profiles
    ADD COLUMN active_user_id BIGINT UNSIGNED
      AS (IF(status = 'active', user_id, NULL)) VIRTUAL,
    ADD UNIQUE INDEX coach_profiles_active_user_id_unique (active_user_id);
  ```
  `down()` drops the index then the column. This is the BR-006 enforcement AD-013 justifies running
  the suite on MariaDB for; there is no SQLite fallback and none is wanted.

### Enums

`ShareLinkType` (`player`, `coach`) with `isSingleUse()`, `defaultTtl(): ?CarbonInterval` (7 days for
coach, null for player) and `maxUses(): ?int`. `CoachStatus` (`invited`, `active`, `inactive`) —
`coach_profiles.status` is currently a plain string default `invited`, so the enum documents and casts
what is already stored. `TrainerPlayerStatus` (`active`, `inactive`).

### Models

- **`ShareLink`** — `BelongsToTenant`. `code` is **not** fillable (minted by the action).
  `isRedeemable(): bool` — active, not expired, uses below `max_uses`. Route-model binding by `code`
  via `getRouteKeyName()`; **binding must not apply the tenant scope**, because a guest redeeming a
  link has no context — resolve it in the action with `withoutTenantScope()` rather than through
  implicit binding, so the fail-closed scope is never worked around in a route file.
- **`TrainerPlayer`** — `BelongsToTenant`, `SoftDeletes`. `player_profile_id`, `trainer_profile_id`
  and `share_link_id` are all non-fillable (AD-016): a request-supplied tenant id is exactly the
  NFR-010 breach.
- **`CoachProfile`** — add `BelongsToTenant`, cast `status` to `CoachStatus`, discharge the TODO.
- **`PlayerProfile`** — `trainerAssociations(): HasMany<TrainerPlayer>` and
  `trainers(): BelongsToMany<TrainerProfile>` through it. Identity, still unscoped.
- **`TrainerProfile`** — `shareLinks()`, `trainerPlayers()`, `playerProfiles()`.
- **`User`** — `trainableProfiles(): Collection<PlayerProfile>` — the self profile plus guarded
  children, deduplicated. This is the single source for the "Who will train with X?" checklist and
  for the profile switcher, so the two can never disagree.

### Actions (`app/Actions/`)

- **`ShareLink/GeneratePlayerShareLink`** — one active player link per trainer; deactivates the prior
  code, mints a new one, audits `share-link.generated`.
- **`ShareLink/RedeemShareLink`** — the correctness centre of this slice.
  ```
  DB::transaction(fn () =>
      ShareLink::withoutTenantScope()->where('code', $code)->lockForUpdate()->first()
      → guard redeemable → create/attach associations → increment uses_count
      → deactivate when single-use → audit)
  ```
  Notifications go in `DB::afterCommit()` — AD-006 is explicit that an SMTP round trip must not be
  held inside a row lock.
- **`Family/AssociatePlayersWithTrainer`** — takes a trainer and a list of `PlayerProfile` ids,
  **re-derives** the permitted set from `trainableProfiles()` server-side and silently drops anything
  outside it (the same pattern `RoleSpecificProfileFieldsTest` already pins for guardianship).
  `firstOrCreate` on the association, so a repeat redemption is idempotent (BR-007: never a duplicate
  account, and never a duplicate association).
- **`Trainer/InviteCoach`** — mints a single-use link with `target_email` and a 7-day expiry, audits,
  queues `CoachInvitation` after commit. Rejects an email that already holds an active coach row.
- **`Trainer/AcceptCoachInvitation`** — creates or reuses the `User`, takes `lockForUpdate` on that
  user's coach rows, writes the `active` `CoachProfile`. The generated-column unique index is the
  real guard; the lock turns a race into a clean failure rather than a 500. A
  `QueryException` on that index maps to the FR-013 copy, never a stack trace.
- **`Trainer/ReleaseCoach`** — sets `inactive`, freeing the constraint (G-11).

### HTTP surface

| Route | Component | Middleware |
|---|---|---|
| `GET /join/{code}` | `Livewire\Join\RedeemShareLink` | none — guests redeem too |
| `POST /context-switch` | `Http\Controllers\ContextSwitchController` | `auth` |
| `GET /trainer/share-links` | `Livewire\Trainer\ShareLinks` | `auth,verified,role:trainer,tenant` |
| `GET /trainer/coaches` | `Livewire\Trainer\Coaches` | same |
| `GET /trainer/players` | `Livewire\Trainer\Roster` | same |

`/join/{code}` renders one of four states: guest → registration form (calling Fortify's
`CreateNewUser` so password rules stay first-party, AD-004); logged-in player → confirm or the family
checklist; logged-in child → refusal (the Gate already denies `trainer.associate`); coach link →
the coach branch. The **roster is a query over `TrainerPlayer` joined to profiles — never
`PlayerProfile::query()`** (AD-001); a review that finds the latter should reject the change.

### Switchers (`app/Livewire/ContextSwitcher`)

Two components, because they answer two different questions (design §"The two switchers"):

- **Trainer switcher** — tenants where any of my trainable profiles has an active `TrainerPlayer`.
  Renders business name and logo only (G-08). Hidden for trainers, coaches and admins.
- **Profile switcher** — my trainable profiles with an active association **in the current tenant**.
  Hidden when there is only one.

Both post to `/context-switch`, which validates the choice against the live association set before
writing `session('trainer_context_id')` / `session('player_profile_id')`. A session value is a
*cache of a permission*, never the permission itself. Rendered in the `app` layout header beside the
role tag.

## Implementation Steps

Each step leaves the suite green; the risky work is front-loaded.

### Step 1 — Tenancy core, no models attached yet

`TrainerContext`, `TenantScope`, `BelongsToTenant`, `TenantContextMissingException`; bind the
singleton. No model uses the trait yet, so nothing can break.

**Verify**: `tests/Unit/Tenancy/TenantContextTest.php` — `runFor()` restores the previous tenant
after both a normal return and a thrown exception; `withoutScope()` restores too.
**Reversible**: pure addition; revert the commit.

### Step 2 — Attach the trait to `CoachProfile` alone

The smallest real tenant-owned model, already carrying the column and already covered by
`CoachProfilePolicyTest`.

**Verify**: existing coach tests still pass once they set a context; a new test asserts a query
with no context returns **zero rows, not all rows** — the assertion that gives fail-closed teeth.
**Risk**: this is where Slice A tests that build coach rows without a context will fail. That is the
point of doing it early and alone: fix the fixtures once, before three more models depend on them.

### Step 3 — `EnsureTrainerContext`

Middleware plus the `tenant` alias in `bootstrap/app.php`. Not yet applied to any route.

**Verify**: `tests/Feature/Tenancy/TrainerContextResolutionTest.php` — one case per resolution rule,
including the coach whose row is `invited` (no tenant) and the player whose session id points at a
revoked association (no tenant, no exception).

### Step 4 — ShareLinks

Migration, `ShareLinkType`, model, factory, `GeneratePlayerShareLink`, `Livewire\Trainer\ShareLinks`.
Codes: `Str::random(32)` from `random_bytes` — AD's risk register flags a guessable permanent code as
a standing unauthorized route into a roster (BR-008).

**Verify**: feature test — a trainer generates a link, regenerating deactivates the old code, a
trainer cannot see another trainer's links (scope), a guest hitting an inactive code gets the
"link no longer valid" screen rather than a 404 that leaks nothing but also says nothing.

### Step 5 — Associations and redemption

`trainer_players` migration, model, factory, `AssociatePlayersWithTrainer`, `RedeemShareLink`,
`Livewire\Join\RedeemShareLink`, the `/join/{code}` route, `ShareLinkWelcome` notification.

**Verify**: feature tests — guest registers and is associated; an existing logged-in player is
associated with a second trainer and **no second account appears** (BR-007); a parent's checklist
associates only the selected members and ignores a submitted id outside the guardianship; a repeat
redemption is idempotent; a child account is refused (the deny list, already in place). Plus a
concurrency test: two redemptions of a single-use coach link, one succeeds and one is refused
(`lockForUpdate` — the row-level assertion NFR-004 exists for).

### Step 6 — BR-006 constraint and coach invitations

The generated-column migration, `CoachStatus`, `InviteCoach`, `AcceptCoachInvitation`,
`ReleaseCoach`, `CoachInvitation` notification, `Livewire\Trainer\Coaches` with Pending/Accepted/
Expired status and resend.

**Verify**: a **database-level** test — insert a second active coach row directly and assert the
driver throws; then the action path returns the FR-013 field error instead. Expiry: an 8-day-old link
is refused with the resend affordance. `ReleaseCoach` then permits the second trainer to accept
(G-11). This is the step that most justifies the MariaDB suite; if it passes on the wrong engine it
proves nothing.

### Step 7 — Switchers and context switching

Both Livewire components, `ContextSwitchController`, layout wiring.

**Verify**: feature tests — a parent with two trainers sees both, switching changes what the
dashboard resolves, a posted `trainer_profile_id` the user has no association with is **refused, not
silently set**; a trainer and a coach see no switcher; the switcher query is asserted to read only
`trainer_players` and `trainer_profiles` (G-08).

### Step 8 — Policies, isolation matrix, seeder

Fill the tenancy branch in all four existing policies; add `ShareLinkPolicy` and
`TrainerPlayerPolicy`. Extend `DemoSeeder` with the multi-trainer scenario the Slice A seeder
deliberately deferred: a child associated with both trainers, a pending coach invitation.

**Verify**: `tests/Feature/Tenancy/IsolationMatrixTest.php` — for every tenant-owned model, trainer A
requesting a valid id belonging to trainer B gets **404 through the HTTP layer with a real session**,
not 403 (AD-011: the global scope makes route-model binding miss by construction, which is the
property worth pinning). Plus `tests/Feature/Tenancy/QueuedJobTenancyTest.php` — a job dispatched
without a context and wrapped in `runFor()` does its work; the same job without `runFor()` is asserted
to no-op, documenting AD-002's failure mode as a test rather than a comment.

### Step 9 — Green suite, specs, memory

Full suite, Pint, PHPStan. Append a `[TASK-001]` Slice B section to `specs/architect-architecture.md`
and update `specs/MANIFEST.md`. Refresh `tasks/TASK-001/test-generator-validation.md` with the Slice B
rows. Capture the two durable facts through `/memory-bank`: fail-closed tenancy in non-HTTP contexts,
and the BR-006 generated column as the reason the suite cannot move off MariaDB.

## Test Plan

**Feature** — the bulk, because these are integration risks:
- ShareLink: generation, regeneration, redemption by guest / existing user / parent checklist,
  inactive and expired codes, single-use exhaustion, idempotent repeat.
- Concurrency: two simultaneous redemptions of a single-use link.
- Coach invitation: invite, accept, expire, resend, release-then-reinvite, second-active refusal.
- Context: resolution per role, revoked association mid-session, switch validation, no-associations
  empty state.
- Isolation matrix: per tenant-owned model, cross-tenant read is 404 and cross-tenant write is refused.

**Unit**: `TrainerContext::runFor()` restoration; `ShareLinkType` TTL and max-uses;
`ShareLink::isRedeemable()` boundaries (expiry to the second, `uses_count == max_uses`).

**Policy**: `ShareLinkPolicy` and `TrainerPlayerPolicy` per role plus a cross-tenant trainer; the
four existing policies re-asserted with a context set and unset.

**Database**: the BR-006 unique index rejects a second active row; the `trainer_players` unique index
permits re-association after a soft delete.

## Verification

```bash
ddev exec php artisan test
```

```bash
ddev exec vendor/bin/pint --test
```

```bash
ddev exec vendor/bin/phpstan analyse --no-progress
```

Plus a schema rebuild with seed data before review. `migrate:fresh` is blocked by
`.claude/hooks/bash-validator.sh`, so rebuild by rolling back and re-migrating, which also exercises
the `down()` methods — memory chunk `MEM-20260902-fcadf3e6` records that they have never run, and the
generated-column migration is exactly the kind whose `down()` fails silently until someone needs it.

## Risks

| Risk | Mitigation |
|---|---|
| **Fail-closed scope silently returns nothing** outside HTTP — AD-021 calls this the most likely subtle bug in the epic | Step 8's queued-job test asserts both the working and the no-op path, so the failure mode is documented executably rather than in a comment |
| **Slice A fixtures break at Step 2** — tests that create coach rows without a context | Attach the trait to one model, alone, early; fix fixtures once |
| **Generated column is MariaDB-only** | Already the committed decision (AD-013); the suite runs on MariaDB. Any future SQLite proposal must first answer how BR-006 is enforced |
| **`lockForUpdate` across a notification** would hold a row lock over SMTP | `DB::afterCommit()`, same as `CreateTrainerAccount`; asserted by the concurrency test's timing-free structure |
| **Guessable ShareLink code** is a permanent route into a roster (BR-008) | 32 chars from `random_bytes`; unique index; regeneration deactivates the predecessor |
| **Session-held context breaks multi-tab** on two trainers | Accepted for MVP (design trade-off); the active tenant is displayed prominently, and `trainer_profiles.slug` already anticipates the `/t/{slug}/…` upgrade |
| **Route-model binding leaking a tenant** if `ShareLink` is bound implicitly on a guest route | Resolve the code inside the action under `withoutTenantScope()`; no implicit binding on `/join/{code}` |
| **A trainer's roster written as `PlayerProfile::query()`** — unscoped identity, a silent cross-tenant read | Stated in the design, restated here, and the isolation matrix fails if it happens |
