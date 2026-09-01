# Architecture

Sports-training platform: a Laravel monolith serving four roles (Super Admin, Trainer, Coach, Player/Parent) across isolated trainer organizations.

## Stack

| Layer | Choice |
|---|---|
| Runtime | PHP 8.4, Laravel 13, DDEV (nginx-fpm) |
| Database | MariaDB 11.8 |
| Frontend | Blade + Livewire + Alpine, Vite, Tailwind, Flux UI |
| Auth | **Laravel Fortify 1.x** + Livewire 4, installed directly (`composer require laravel/fortify livewire/livewire` + `fortify:install`). Laravel 13's starter kits are project templates for `laravel new`, not installable into this existing repository; `laravel/breeze` is legacy |
| Queue / schedule | `database` driver for MVP; a worker and the scheduler are **required runtime processes** (see AD-008) |
| Tests | PHPUnit 12, run against **MariaDB**, not SQLite — the schema relies on MariaDB-only DDL (see AD-013) |
| Static analysis | **Larastan level 5**, clean with no baseline (`composer analyse`); levels 6/7/8 report 17/20/55 and are a separate ratchet |

## Layering And Dependency Direction

```
routes/web.php
  └─ middleware: auth → verified → EnsureTrainerContext → EnforceImpersonationTimeout
       ├─ Livewire components   interactive screens (tables, grids, forms)
       └─ Controllers           redirect-only endpoints (/join, context-switch, impersonate)
            └─ Actions          app/Actions/<Domain>/ — one use case, invokable, transactional
                 └─ Services    app/Services/ — stateless collaborators, no persistence decisions
                      └─ Models app/Models/ — relationships, casts, scopes
```

Controllers and Livewire components stay thin. Authorization is a Policy check, never a UI concern. The only interface at an integration boundary is `ApprovedPurchaseExecutor` (AD-006).

## Directory Plan

```
app/
  Actions/{Trainer,ShareLink,Family,Approval,Availability,Admin}/
  Contracts/          ApprovedPurchaseExecutor
  Enums/              Role, UserStatus, ShareLinkType, CoachStatus, ApprovalStatus, PaymentType
  Http/Middleware/    EnsureTrainerContext, EnforceImpersonationTimeout, DenyChildAbilities
  Livewire/{Admin,Trainer,Family,Availability}/
  Policies/
  Services/           TrainerContext, AvailabilityResolver, CoachConflictChecker, AuditLogger
  Support/Tenancy/         BelongsToTenant, TenantScope
  Support/Authorization/   ChildAbilities
```

## Architecture Decisions

Decisions 1–7 of `tasks/TASK-001/brainstorming-design.md` are ratified as binding; their rationale is not repeated here. The rulings below are the architectural consequences that document does not settle.

### AD-001 — Single-database tenancy, fail-closed, with three data classes

A `BelongsToTenant` trait applies `TenantScope` and auto-fills `trainer_profile_id`. With no resolved tenant the scope applies `whereRaw('0 = 1')` rather than returning every row: a missing context becomes an empty list somebody reports, never a silent cross-tenant read.

The classification is load-bearing and must be stated on every new model:

- **Tenant-owned** (carries `trainer_profile_id`, scoped): `ShareLink`, `TrainerPlayer`, `CoachProfile`, `CoachAvailabilityOverride`, and later events, tokens and content.
- **Identity** (never scoped): `User`, `TrainerProfile`, `PlayerProfile`, `ImpersonationLog`, `UserDeletionLog`, `AuditLog`.
- **Scoped through their owner**: `Availability`, `PurchaseApproval` — reached via the owning profile, never queried directly from a trainer screen.

A trainer's roster is always a query over `TrainerPlayer` joined to profiles, **never** `PlayerProfile::query()`. Reachability of a person inside a tenant is the association row, not a column on the person.

### AD-002 — Tenancy outside the HTTP request is explicit, never implicit

The consequence of AD-001 that most often ships as a bug: a queued job, an Artisan command and the scheduler have **no session**, so a fail-closed scope makes every tenant-owned query return zero rows. The failure is silent — the job succeeds having done nothing.

Rules:

- `TrainerContext` exposes `runFor(TrainerProfile $tenant, Closure $work)`, which sets the context, runs the closure and restores the previous value in a `finally`.
- Any job touching a tenant-owned model either carries its `trainer_profile_id` and wraps its work in `runFor()`, or declares itself system-wide and calls `withoutTenantScope()` explicitly.
- A queued job never serializes the *context*; it serializes the tenant id and re-resolves. Context is request state, not payload.
- Feature test: a job dispatched with no context must not silently no-op.

### AD-003 — Super Admin reads through an explicit inspect context, not a blanket escape hatch

`withoutTenantScope()` exists but is gated on Super Admin and is not the normal admin read path. The Users directory queries `User`, which is identity and unscoped, so the main admin screen is unaffected by AD-001 entirely.

When an admin needs to see inside one organization, they select an **explicit read-only inspect tenant** that populates `TrainerContext` the same way a player's switcher does. Same code path as every other user, one auditable entry point, and no query in the codebase that reads across all tenants by default.

### AD-004 — Registration exists only at the invitation endpoint

BR-003 forbids trainer self-registration and players only ever arrive through a ShareLink, yet the scaffolded Fortify `/register` route lets anyone create an unassociated account. Gating that route with middleware leaves a dead endpoint whose only job is to refuse.

Ruling: **disable `Features::registration()`** in `config/fortify.php` and own the registration surface as a Livewire component mounted under `/join/{code}`. It calls Fortify's `CreateNewUser` action so password rules and validation remain first-party. One entry point, no route that must be defended, and the ShareLink is a precondition of the form rather than a check inside it.

Login stays entirely Fortify. A custom `Fortify::authenticateThrough()` pipeline step rejects inactive and deleted accounts with the exact FR-017 copy, positioned **behind** the throttle step so the message cannot become an unthrottled account-enumeration oracle.

### AD-005 — Authorization is an enum plus Policies; no permissions package

Four compile-time roles (BR-002) with no runtime role editing anywhere in scope. `spatie/laravel-permission` would add three tables, a cache layer and a second source of truth while still not expressing the two rules that matter — tenant membership and the child deny list — because both are contextual rather than role-static.

Every policy asks in order: **tenant membership → role → child deny list**. Tenancy first, because a check that passes on role but fails on tenancy is precisely the NFR-010 breach. `Gate::before` grants Super Admin **only when no impersonation is active**.

The child deny list is a single `ChildAbilities::DENIED` array consulted by one `Gate::before` hook, with a test that iterates the array and asserts each entry is refused.

**Revisit trigger:** a request for trainer-defined custom permissions for coaches (currently Phase 2, "Advanced permission customization per user").

### AD-006 — Epic boundaries are interfaces; one interface, not scattered stubs

`ApprovedPurchaseExecutor` is bound to `NullPurchaseExecutor` in this epic and rebound to the Stripe/token implementation in Epic-05. It is the only seam. No other stub, feature flag or `if (class_exists(...))` is permitted to represent a future epic.

No repositories. Eloquent models, scopes and the query builder are already the persistence seam; nothing in this epic plans a second backend.

### AD-007 — Side effects run after commit, never inside a lock

ShareLink redemption holds `lockForUpdate` on the link row for correctness under NFR-004. Sending a welcome email inside that transaction holds a row lock across an SMTP round trip.

Rules: every Notification is `ShouldQueue`; every dispatch that follows a write happens after the transaction commits (`DB::afterCommit()`, or queued listeners which Laravel defers automatically). A rollback must never leave a sent email describing a registration that did not happen.

### AD-008 — Two runtime processes are functional requirements, not ops detail

Neither the requirements nor the design says this outright, and without it two specified behaviours silently never occur:

- A **queue worker** must run, or every notification (approval requested, coach invited, ShareLink welcome) sits in the `jobs` table forever.
- The **scheduler** must run, or the 48-hour approval expiry (BR-015, NFR-009) and `CloseStaleImpersonationLogsJob` never fire, leaving approvals pending indefinitely.

Both are configured in DDEV for local parity and belong in the deployment checklist. `database` queue driver for MVP; Redis when queue depth justifies it.

### AD-009 — Route-model binding is the IDOR control

`Model::resolveRouteBindingQuery()` builds its query through the model, so global scopes apply during binding resolution. A trainer requesting a valid id belonging to another tenant therefore gets a 404 **by construction**, before any controller code runs — no per-controller ownership check to forget. This property is why AD-001 is worth its cost, and the isolation test matrix asserts it through the HTTP layer with a real session.

### AD-010 — Admin screens are hand-rolled Livewire for this epic

Filament is the usual default for admin panels, and is rejected here on scope: the Users tool is one table with four row actions, and impersonation, deactivation and anonymization are custom actions under any framework. Filament would introduce a second panel, routing and theme stack beside the starter kit's Flux UI, and could not serve the trainer-facing screens anyway, since those carry per-tenant branding.

**Revisit trigger:** Epic-07 (Super Admin Tools). If admin scope grows past a handful of tables, Filament's table and form builders start paying for the second stack.

### AD-011 — In-app notifications are database-backed and polled

Approval requests need an in-app indicator (FR-010). Use the `database` notification channel rendered on page load, with `wire:poll` on the bell for freshness. Laravel Reverb and a WebSocket connection are not justified by a notification that arrives a few times a day.

**Revisit trigger:** Epic-02 live rosters or any chat feature.

### AD-012 — Directory pagination is sized for the stated scale

NFR-002 asks for a 10,000-user list under 3 s. Server-side pagination with an index on `(role, status)`; avoid an unbounded `COUNT(*)` on every keystroke — debounce the search input and prefer `simplePaginate()` unless exact page counts are required. Search is tool-scoped by requirement, so it never fans out across unrelated tables.

---

### AD-014 — Livewire components are class-based

Livewire 4 generates **single-file components** by default, into `resources/views/components/…` with a `⚡` filename prefix. This project uses `php artisan make:livewire <Name> --class`, keeping components in `app/Livewire/` as the directory plan above states.

Reason: components here are the HTTP entry points of the layering — they authorize, then delegate to an Action. That logic belongs in a class with a policy check and a component test, not inline in a Blade file. Recorded because the default is the other way, and an unflagged `make:livewire` will quietly produce the wrong shape.

---

### AD-015 — Account status is a per-request invariant, not a login check

FR-017/FR-018 read as login rules, and implementing them only in the Fortify pipeline leaves a
deactivated user inside a live session for its whole lifetime — seven days under Q-01.07, longer
with a remember-me cookie. `EnsureAccountRemainsActive` therefore re-checks `users.status` on
every request and terminates the session when it is not active; `EnsureAccountIsActive` stays in
the login pipeline so the sign-in form can show a field-level error instead of a bare redirect.

The middleware is appended to the **`web` group**, not to the `auth` group in `routes/web.php`.
Fortify's own authenticated routes (profile, password, verification) and Livewire's `/update`
endpoint live outside that group, and a deactivated session must not keep mutating data through
them.

**Consequence for Slice D:** the deactivate action needs no session bookkeeping of its own. Setting
`status` is sufficient, and the next request from that user ends their session.

---

### AD-016 — Privilege and ownership columns are never mass-assignable

`users.role`, `users.status` and `users.is_child_account` decide who someone is;
`player_profiles.owner_user_id`, `coach_profiles.trainer_profile_id` and the other owner columns
decide which tenant or family a record belongs to. None of them appear in a `#[Fillable]`
allow-list. `audit_logs` has no allow-list at all — Eloquent guards every attribute — and is
written only through `AuditLogger::log()` with `forceFill`.

Reason: one `update($request->validated())` anywhere in a later slice would otherwise be a
role escalation or a tenancy breach, and NFR-010 puts leakage at 0%. Actions that legitimately set
these columns use `forceFill` or the relationship (`$user->trainerProfile()->create(...)`), which
makes the deliberate write visible at the call site. Factories run under `Model::unguarded()`, so
seeders and test fixtures are unaffected.

---

### AD-017 — The trainer invitation carries no token

`TrainerInvitation` links to the password-request form rather than embedding a reset token. A
token-bearing link inherits the broker's 60-minute TTL (NFR-009), which is fine for a reset the
user just asked for and useless for an invitation opened the next morning. Minting the token from
the form means the trainer creates it when they are ready to use it.

Two properties follow: the mail never goes stale, and nothing sensitive reaches the serialized
queue payload — the `database` queue driver persists job payloads, and `password_reset_tokens`
stores only a hash precisely so the plaintext does not sit at rest.

**Cost, accepted:** one extra step for the trainer (entering the address the mail already shows
them), and no email verification via the invitation link itself.

---

### AD-018 — The unauthenticated auth surface is rate-limited and audited

Fortify ships a limiter for login only. A `fortify` limiter now covers every unauthenticated write
it registers — password reset above all, which is also the trainer onboarding path — at 10/minute
per IP, with read-only view routes exempt via `Limit::none()` so a reloaded sign-in page cannot
lock anyone out (NFR-007).

Auth events write to the same audit trail as everything else (NFR-011): login, logout, failed
attempt, throttled request, a session terminated by AD-015, and every authorization denial. Two
wiring details are non-obvious and cost time to rediscover:

- Listener methods are named `audit*`, not `handle*`. Laravel discovers listeners in
  `app/Listeners` by the `handle` prefix, which double-registers them on top of explicit wiring.
- Denials and throttles are audited from `withExceptions()->render()`, not `report()`. Laravel
  ignores `AuthorizationException` and `HttpException` for reporting, and `render()` runs
  `prepareException()` before matching callbacks — so the callback must be typed against
  `AccessDeniedHttpException`, not `AuthorizationException`. With a custom login limiter
  configured, Fortify never reaches the action that fires `Lockout`, which is why the throttle is
  audited from the exception layer too.

The attempted address is recorded; the submitted password never is — `Failed` carries it in
`$credentials`, so the payload is picked apart by hand rather than passed through.

---

### AD-013 — The test suite runs on MariaDB

`phpunit.xml` ships pointing at `sqlite::memory:`, which cannot execute this schema: BR-006 is enforced by a MariaDB generated column using `IF()`, and the two engines diverge exactly on that feature — SQLite has partial indexes, MariaDB does not, which is the whole reason the generated column exists.

Ruling: the suite runs against a MariaDB test database in DDEV. An in-memory SQLite run would leave the database-level invariant the architecture depends on completely unexercised while appearing green. Speed is not worth a test suite that cannot fail on the thing most likely to break.

---

### [TASK-001] Epic-01 User Management & Authentication (2026-09-01)

**Module:** Identity, tenancy, onboarding, family accounts, admin tooling

**Placement:**
- Routes: `routes/web.php` — `/join/{code}` (registration + association), `/dashboard`, `/profile`, `/family`, `/approvals`, `/availability`, `/coach/my-times`, `/trainer/{share-links,coaches,branding}`, `/admin/{users,impersonation-history}`, `POST /admin/impersonate/{user}`, `POST /impersonate/stop`, `POST /context-switch`
- Middleware: `EnsureTrainerContext`, `EnforceImpersonationTimeout`, `DenyChildAbilities`
- Actions: `app/Actions/{Trainer,ShareLink,Family,Approval,Availability,Admin}/`
- Services: `TrainerContext`, `AvailabilityResolver`, `CoachConflictChecker`, `AuditLogger`
- Contract: `app/Contracts/ApprovedPurchaseExecutor.php` → bound to `NullPurchaseExecutor` in `AppServiceProvider`
- Models: `User`, `TrainerProfile`, `CoachProfile`, `PlayerProfile`, `TrainerPlayer`, `ShareLink`, `Availability`, `PurchaseApproval`, `ImpersonationLog`, `CoachAvailabilityOverride`, `UserDeletionLog`, `AuditLog`
- Policies: one per model, evaluated tenant → role → child deny list

**Decisions:**
- Identity and personhood are separate tables: `User` is a credential, `PlayerProfile` is a trainable person. Every Player/Parent registration creates one self profile, so "parent who also trains" (BR-022) needs no fifth role and no branching.
- Availability carries a **nullable** `trainer_profile_id`: null is the default set, non-null wholly replaces it for one trainer. Reads go through `AvailabilityResolver`; the table is not tenant-scoped, and trainer-side filters preserve isolation by joining the already-scoped `TrainerPlayer`.
- Approval transitions are conditional updates (`where('status','pending')->update(...)`) inside a transaction, with the executor running only if a row was affected. Double-approve idempotency is established now so Epic-05 inherits it.
- Impersonation writes its session keys **before** `Auth::login()`; `SessionGuard::updateSession()` calls `regenerate(true)`, which issues a new session id and a fresh CSRF token while preserving session data, so the keys survive. `Auth::logout()` is never called on the way in. Exit re-authenticates the impersonator by id — there is no second parallel session.
- Every audited write during impersonation records both `actor_user_id` and `on_behalf_of_user_id`.
- GDPR deletion retains a minimal, purpose-justified log: original user id, **salted email hash**, actor, timestamp, reason — no data payload, with a configurable retention horizon.
- BR-006 ("one active trainer per coach") is enforced by the database on MariaDB via a generated column `active_user_id = IF(status='active', user_id, NULL)` with a unique index, since partial unique indexes are unavailable.

**Risks:**
- **Fail-closed tenancy in non-HTTP contexts** silently returns nothing rather than failing loudly — mitigated by AD-002 and a test asserting a context-less job does not no-op. This is the most likely source of a subtle bug in this epic.
- **`users.is_child_account` is denormalized** and can drift from the derived truth. One action writes it; an invariant test asserts it always agrees with the backing profile's `is_child`.
- **Session-held tenant context** breaks multi-tab use across two trainers. Accepted for MVP; the documented upgrade path is a URL prefix (`/t/{slug}/…`), which `trainer_profiles.slug` already anticipates.
- **ShareLink codes must be high-entropy** — a player link is permanent and unlimited-use (BR-008), so a guessable code is a permanent unauthorized route into a roster.
- **SVG logo uploads** (permitted by FR-019) are an XSS vector served to every user in the organization. Pending a product call; restrict to PNG/JPG or sanitize before storage.
- **Epic-02/05 coupling**: the approval domain ships fully tested against a test double but has no checkout UI until events exist. Slice C's demo is the parent queue, not a purchase.
