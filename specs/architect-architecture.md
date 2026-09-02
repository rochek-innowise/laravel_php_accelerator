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

**Amended in Slice C — there are now two surfaces, not one.** FR-008's child-login toggle on
`/family/children/create` also calls `CreateNewUser`, because FR-011 requires a child to be able to
log in and no requirement describes any other way for that login to come into existence. The "one
entry point" ruling above no longer holds literally, and the second surface is only defensible
because it carries the same protections the first one does:

- It is reachable only by an authenticated non-child `Player`, so it is not an anonymous surface.
- It carries its own rate limit, keyed per guardian **and** address, because an authenticated
  guardian could otherwise mint unlimited accounts on arbitrary addresses — which would also make
  the uniqueness failure the account-enumeration oracle this AD exists to prevent, and would let
  someone squat an address so its owner could never register through `/join/{code}`.
- Refusals are audited, per AD-018.
- The login it creates is marked verified at creation (see AD-023), since it fires no `Registered`
  event and FR-011 gives a child no way to verify an address they may not control.

Anything that adds a **third** surface should be treated as a design change requiring its own
decision, not as precedent set by this one.

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

### AD-019 — Guardianship is a relation, not an owner column

`player_profiles.owner_user_id` could hold one guardian, so the ordinary case — a mother and a
father on the same child — had no representation. Guardianship moves to `player_guardians`
(`player_profile_id`, `guardian_user_id`, `relationship`, `is_primary`, unique on the pair).

A **self profile carries no guardian row**. The person is reached through `user_id`, and inventing
a row where someone guards themselves would put a special case in every query that walks the
relation.

Consequences that are easy to get wrong later:

- `PlayerProfilePolicy` resolves reachability as `user_id === $user->id || isGuardedBy($user)`.
  `manageTrainerAssociations` requires guardianship **and** `! is_child_account`, so a child with
  its own login can view its profile but never manage its trainer associations (FR-011).
- "Parent" stays emergent (BR-022): `User::isParent()` is guarding at least one child, never a
  role or a column.
- FR-016's emergency contact lives on **each child's** profile, edited by any of its guardians —
  not on the guardian's own profile. A guardian who does not train has no self profile, so the
  field would otherwise be unreachable for the most common parent; and a trainer looking after
  that child reads the child's record, not the parent's.
- Child ids reach the client in the profile form and a tampered snapshot does change them. Each
  id is re-resolved through the acting user's guardianship, so an unrelated profile is skipped
  silently rather than refused — a 403 there would confirm the profile exists.

**Migration note:** existing owners became primary guardians, except where the owner was the
profile's own login. The reverse migration restores `owner_user_id` as nullable, because a child
with two guardians has no single owner to restore.

---

### AD-020 — Profile photos are private and served through a signed route

Photos live on the **private** `local` disk (`storage/app/private`), never on `public`. A child's
photo behind a guessable URL is a leak that no later access-control layer can undo, so placement
itself is the access decision (AD-001's reasoning applied to files).

Every read goes through `ProfilePhotoController`. The signature bounds the link's lifetime; the
**policy decides who may follow it**. Both are required: a valid signature alone would mean a link
shared once grants access forever, and a policy check alone would mean the URL never expires.
Links are minted per page render with a short TTL and are never stored, cached or emailed.

Validation is layered, because MIME sniffing is necessary but not sufficient:

1. `mimetypes:` (finfo, actual bytes) rejects a renamed script before anything is written.
2. The decoder is the second gate. A file can sniff as an image and still fail to decode; that
   path deletes the partial upload and surfaces a field error rather than a 500.

Note for whoever writes the next upload feature: `UploadedFile::fake()` derives its MIME type from
the extension, so in tests a renamed script *claims* to be a JPEG and passes step 1. Real uploads
are sniffed by finfo and stop there. A test asserting step 1 with `createWithContent` is testing
the fake, not the rule.

One column carries both variants: the thumbnail is a deterministic `_thumb` suffix on
`users.photo_path`, so no migration was needed. Writes are ordered original → thumbnail → delete
previous, so a failure anywhere leaves the user with the photo they already had.

Local, not S3 or MinIO: `Storage::disk()` keeps the feature disk-agnostic, so moving to S3 is a
config change (`PROFILE_PHOTO_DISK`) rather than a code change. A container bought nothing here.

---

### AD-013 — The test suite runs on MariaDB

`phpunit.xml` ships pointing at `sqlite::memory:`, which cannot execute this schema: BR-006 is enforced by a MariaDB generated column using `IF()`, and the two engines diverge exactly on that feature — SQLite has partial indexes, MariaDB does not, which is the whole reason the generated column exists.

CI runs the same suite against a MariaDB service container (`.github/workflows/ci.yml`).
`phpunit.xml` holds DDEV defaults but sets an env entry only when the variable is absent, so the
workflow points the suite at the container by exporting `DB_*` — verified by running the suite
locally with CI-style credentials rather than assuming it.

Ruling: the suite runs against a MariaDB test database in DDEV. An in-memory SQLite run would leave the database-level invariant the architecture depends on completely unexercised while appearing green. Speed is not worth a test suite that cannot fail on the thing most likely to break.

---

### AD-021 — The purchase-approval domain is owner-scoped, not tenant-owned

`PurchaseApproval` is reached only through its owning `PlayerProfile`, never from a trainer screen
or a tenant query. Unlike `TrainerPlayer` (tenant-owned, `BelongsToTenant`), purchase approvals
carry no `trainer_profile_id` and are not scoped by the active tenant — they are AD-001's third
data class (alongside `User`, `TrainerProfile`, identity relations): reachable via identity reads
only.

The consequence: a guardian's approval queue reads through `PlayerProfile::purchaseApprovals()`, a
per-child relation bearing the child's owner, never through a tenant-scoped join. Isolation is by
reachability, not by column. This design sidesteps the row-locking problem that would arise if every
guardian approval held a tenant context and approval writes contended for the same row under
separate `runFor()` scopes.

---

### AD-022 — The executor placement is a defer for Epic-05, not a bug

`ApprovedPurchaseExecutor::execute()` is invoked **inside the transaction** that performs the
`pending` → `approved` transition — see `RespondToPurchaseApproval::handle()` and the token-bypass
path in `RequestPurchaseApproval::handle()`, both wrapping the state update and executor call in
`DB::transaction()`.

This is safe today only because `NullPurchaseExecutor` does nothing: it writes an audit entry and
returns. **Once this becomes a real payment call** (a Stripe charge, a token ledger write, any
network round trip), the placement becomes a double-charge trap on two fronts:

1. **Lock duration**: the executor runs while the approval row is held for update, so a slow network
   call holds the lock for seconds.
2. **Rollback after charge**: a successful charge followed by a transaction commit failure rolls the
   status back to `pending` while the charge stands, and a retry applies the conditional-update
   guard (`where('status', 'pending')`) to a row still in pending state, charging twice.

**Epic-05 must make a design decision** before this interface backs a real payment call: either move
the call behind `DB::afterCommit()` (which trades double-charge for committed-unapproved: a
committed `approved` status with nothing charged if the after-commit call fails), or introduce an
execution-state column and an outbox pattern so both the status and the execution state commit
atomically.

The conditional-update guard itself is load-bearing and must remain in every approved path:
`RespondToPurchaseApproval` uses it to make double-approvals idempotent, and `ExpirePurchaseApprovalsJob`
uses it to ensure a row a guardian just approved in the same second cannot be double-flipped to
expired. That guard cost nothing to establish now and is the baseline every solution must build on.

---

### AD-023 — A child login can reach its approvals but cannot manage anything

A child with its own login (`is_child_account = true`) is reachable as `PlayerProfile::user_id` and
may access its own profile for reading. The `/approvals` screen displays only its own pending
requests, reached through `PlayerProfile::purchaseApprovals()`, so the child sees the consequences
of its actions.

The deny list (AD-005) forbids `trainer.associate`, `purchase.complete`, `tokens.purchase`,
`payment-method.*`, `account.delete`, `trainer-association.change`, `parent-data.view`, and is
the single source of truth for all child restrictions — no duplicate checks elsewhere.
`PlayerProfilePolicy::manageTrainerAssociations()` explicitly refuses a child login even for its
own profile, so a child and its guardians never disagree about who may edit the profile's
associations.

**Guardian-created child logins are marked verified at creation**: the profile-and-user creation
happens in one transaction in `CreateChildProfileAction`, and if a login is requested, it is
created with `is_child_account = true` and the user is marked verified (no separate
`Registered` event, no verification flow) because the child owns no email address the parent may
not control and has no path to verify it anyway.

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

---

## [TASK-001] Slice B — Tenancy, Invitations And Associations (implemented)

What the code does now, where it diverged from the plan, and why. Slices C and D are unchanged.

### Two escape hatches, not one

AD-001 describes a single escape from `TenantScope`, gated on Super Admin. The implementation has
**two**, because a guest redeeming an invitation has no organisation and no admin:

| Escape | Call site | Gate |
|---|---|---|
| `Model::withoutTenantScope()` | Admin inspection (AD-003) | Throws unless the actor is a Super Admin |
| `TrainerContext::runAsSystem(Closure)` | ShareLink lookup, coach-status checks, the switcher's own membership query, system jobs | None — explicit and greppable by design |

Both are deliberately noisy to read. `runAsSystem` is the one to scrutinise in review: it is
reachable without authentication, so every call site must be a path that legitimately predates a
tenant. There are five today.

### Coach employment is a history of rows, so the relation must be ordered

A released coach keeps their row (G-11) and gains a second one on re-hire, so `coach_profiles` holds
one row per employment rather than one per coach. `User::coachProfile()` therefore orders the active
row first: an unordered `hasOne` returned the oldest, released row, which resolved to no tenant and —
under fail-closed tenancy — gave a legitimately re-hired coach an empty screen on every request.

`CoachProfilePolicy::view/update` grant on `$user->id === $coachProfile->user_id`, which is correct
against that multi-row reality (a coach may read their own history), but any future write path must
target the active row explicitly rather than "the" profile.

### Identity relations stay tenant-blind

`CoachProfile` and `TrainerPlayer` are tenant-owned, but three relations keyed on an identity's own
primary key bypass the scope, and the distinction is the load-bearing part:

- `User::coachProfile()` — a coach reading their own row, and the query that *resolves* their tenant.
  Scoping it would make context resolution circular.
- `PlayerProfile::trainerAssociations()` — one person's memberships across organisations; the data
  behind the family view and the trainer switcher.

The rule that emerged: **a relation keyed on an identity's own id is an identity read; a query that
begins at `TrainerPlayer::query()` or `CoachProfile::query()` is a tenant read.** A trainer's roster
must therefore be `$trainer->playerProfiles()` (through the association), never `PlayerProfile::query()`
— asserted by `IsolationMatrixTest::a_trainers_roster_never_reads_player_profiles_directly`.

### Middleware placement

`EnsureTrainerContext` is appended to the **`web` group**, not to the `verified` route group as
planned. Livewire's update endpoint is not inside the route group, so a component that rendered
correctly on first paint would return an empty list on its next round trip. This mirrors the reason
`EnsureAccountRemainsActive` sits there.

### Policy scope, narrowed

The tenancy branch answers exactly one question — *is the resolved organisation this trainer's own?*
An earlier draft also required the trainer to have a profile at all; that is a data-integrity
question, not an authorisation one, and it broke a passing Slice A test. Left to the action, which
cannot mint a link without a profile anyway.

### Who may accept a coaching invitation

Acceptance rewrites `users.role`, so it is gated twice. Only a `Player` or an existing `Coach` may
accept — a Super Admin who followed a forwarded link was otherwise demoted and lost `isSuperAdmin()`
with no path back, and a Trainer was demoted while their `TrainerProfile` survived, orphaning the
organisation. Acceptance also requires a **verified** address: the `target_email` comparison is
worthless against an address the redeemer typed in seconds earlier, and a wrong acceptance both
takes the BR-006 slot and spends the single-use link.

The consequence for the guest flow: following a coach link creates the account and sends the
verification mail, but does **not** enrol. The coach verifies and reopens the link. This is
Q-01.05a applied consistently — verification is required to act, and joining a staff is acting.

### Rate limiting lives in two places

`/join/{code}` is a GET, so `throttle:join` on the route bounds code probing only. The writes arrive
on Livewire's update endpoint, which no route-level limiter can reach, so account creation is
limited inside the component under the same limiter name. A route limiter alone would have looked
like protection while protecting nothing.

### Verified by the database, not the code

`coach_profiles.active_user_id` is a MariaDB generated column (`IF(status='active', user_id, NULL)`)
with a unique index. `CoachInvitationTest` asserts it by inserting through the query builder,
bypassing every action: if the index were dropped, only that test would notice.

### Tenancy resolution is resolved once per request

`ResolvesAvailableTenants` answers "which organisations can this account reach?" for both the
middleware and the trainer switcher, caching the result on `TrainerContext`; `User::trainableProfiles()`
is memoized per instance. Before that, the middleware and both switchers each derived the whole
membership set independently and a player page load cost 11 queries, nine of them the same three
repeated. It is now 4, held by `TenancyQueryBudgetTest`.

`TrainerContext` is bound `scoped`, not `singleton`: it now carries that cache, and the middleware
only ever *sets* a context — it never clears one, so on a persistent runtime a guest request reusing
a worker would inherit the previous user's tenant.

**Not verified:** migration `down()` methods still have not been executed — the project's bash
validator blocks `migrate:rollback` and `migrate:fresh`, so the generated-column teardown (which
must drop the index before the column) is unexercised. See `MEM-20260902-fcadf3e6`.

---

## [TASK-001] Slice C — Family Profiles, Approvals And Child Accounts (implemented)

Slice C ships child profiles with optional child logins, guardian-managed trainer associations, and
a 48-hour purchase-approval domain exercised end-to-end against a test double.

### The approval state machine and idempotency guard

A purchase initiates a `PurchaseApproval` with status `pending` (or already `approved` if it bypasses
approval via BR-014) and `expires_at = now() + 48 hours` (NFR-009). A guardian approves or denies
from `/approvals`, and `RespondToPurchaseApproval` performs the state transition with a conditional
update:

```sql
UPDATE purchase_approvals
SET status = ?, responded_at = now(), parent_note = ?
WHERE id = ? AND status = 'pending'
```

Only if one row is affected does the executor run and the guardian is notified. A double-click or a
race with `ExpirePurchaseApprovalsJob` changes nothing — the second call finds the row already
transitioned and returns false. The job applies the same conditional guard per row, so a row
approved in the same second as the sweep cannot be double-flipped to `expired`. This idempotency
pattern was established in Slice C so Epic-05 inherits correctness rather than discovering it later.

### Child registration: optional login on profile creation

`CreateChildProfileAction` accepts an optional `wantsLogin` flag with email and password. If true:

1. `CreateNewUser` validates and creates the `User` (reused from `Fortify\CreateNewUser`).
2. `User::is_child_account` is set to `true` via `forceFill`.
3. The user is marked **verified** at creation — no verification email, no separate event, because a
   child owns no email they control.
4. The `PlayerProfile` is created with `user_id` pointing to the new `User`.
5. Both operations happen in a single transaction, so the invariant `MEM-20260902-063160c0`
   (is_child_account must agree with the backing profile's is_child) is first exercised in
   production, not just over seeded data.

A profile-only child (no login) remains fully supported. The distinction is enforced at action entry:
a profile-only child throws on `RequestPurchaseApproval` because there is no login to initiate
the purchase.

### Child-login restrictions and the ShareLink experience

A child login is denied everything on the `ChildAbilities::DENIED` list (AD-005) — including
`trainer.associate`, which fires when a child tries to join via `/join/{code}`.

Rather than a bare 403, the `/join/{code}` component detects the child login and renders friendly
copy: "Ask a parent to enrol you", plus `ChildShareLinkBlocked` notification to all guardians
carrying the link code. The refusal is throttled per child login per Share Link (using
`RateLimiter` the same way `register()` throttles registration) because the join attempt sits
behind Livewire's update endpoint, unreachable to a route-level limiter.

### Guardian-scoped trainer associations and the `/family` screen

`/family` lists every child and their trainers, with add/remove controls for each association,
guarded by `PlayerProfilePolicy::manageTrainerAssociations()` (AD-023). A guardian may:

- **Add from existing trainers**: choose a trainer the guardian already has in any other child,
  reusing `AssociatePlayersWithTrainer` unchanged from Slice B.
- **Add via manual code**: enter a ShareLink code, which calls `RedeemShareLink::forPlayer()`
  directly from the Livewire component — the same code path `/join/{code}` uses, now parametrized
  to accept a trainer id instead of deriving it from the session.
- **Remove**: soft-delete the `TrainerPlayer` row, preserving history (FR-009). The unique index
  `(trainer_profile_id, player_profile_id, deleted_at)` from Slice B makes re-adding after removal
  a new row, not a resurrection.

A child with its own login may view the screen but never change it — `manageTrainerAssociations`
returns false for any child account, even its own profile (AD-023).

### Deliberate deferrals in Slice C

- **"Request more info"** (FR-010 gap): Slice C ships Approve/Deny only. Adding a parent-note-only,
  non-terminal response is deferred; the `parent_note` column already exists, so the feature is
  one endpoint away.
- **Profile photo thumbnailing**: Slice C stores and serves full-size photos only. The
  `_thumb` naming convention (Decision 5 in the plan) is ready; thumbnail generation is deferred.
- **N+1 on `/family` query**: one call to load a family's trainers fans out per child rather than
  preloading the set. Accepted and pinned by `TenancyQueryBudgetTest`; the budget is still met with
  the fan-out, and restructuring the query would complicate the permission logic.

### Routes and scheduler

Three new routes under the `player` role group:

| Route | Component | Purpose |
|---|---|---|
| `GET /family` | `Livewire\Family\FamilyOverview` | Lists the guardian's children and their trainer associations |
| `GET /family/children/create` | `Livewire\Family\ChildForm` | Form to create a child profile with optional login |
| `GET /approvals` | `Livewire\Family\PendingApprovals` | Lists pending purchase approvals for all of the guardian's children |

**The scheduler is now a required process** (AD-008). `routes/console.php` registers
`ExpirePurchaseApprovalsJob` to run every 15 minutes. Without it, pending approvals never expire,
leaving them in the queue indefinitely. The job applies the same conditional-update guard
`RespondToPurchaseApproval` uses, so a late run is safe — just delayed.

### Test coverage

Slice C closes the test-generator validation map for all Slices A–C: 329 tests / 939 assertions,
PHPUnit 12 against MariaDB, PHPStan level 5 clean, Pint clean. Coverage includes:

- End-to-end child profile creation with and without login (invariant integrity tested)
- Guardian-scoped trainer association add/remove, with re-add after soft delete
- Child login denial on `/join/{code}` with throttled guardian notification
- Purchase approval request, guardian approval/denial, and 48-hour expiry with conditional-update
  idempotency
- Child-login profile access (read permitted, write denied except own basic fields)
- Profile photo upload, validation, and signed-route serving (full-size; thumbnailing deferred)

### Security findings closed

The security review found one High risk: a forged `trainer_id` on the child form wrote a
`trainer_players` row into an arbitrary organisation (cross-tenant write, NFR-010 breach).
**Fixed**: submitted trainer ids are now intersected against the family's own trainers before
association. Two regression tests pin the fix:
`tests/Feature/Family/TrainerAssociationSecurityTest::test_a_forged_trainer_id_is_refused` and
`::test_association_fails_if_the_trainer_is_not_in_the_family`.

Code review findings closed: the empty-picker field error (now a properly formatted validation
message), and nine follow-ups all addressed. One false positive was correctly rejected:
`Illuminate\Notifications\Notification` already uses `SerializesModels`, so no queue payload
leaks model data.
