# Epic-01: User Management & Authentication — Design

**Task**: TASK-001
**Input**: `tasks/TASK-001/requirements-analyst-requirements.md`, `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`
**Status**: Design drafted — 9 decisions need product/legal confirmation (marked ❓)

> **Note on process.** This design was produced in a non-interactive agent context, so the
> normal one-question-at-a-time dialogue could not run. Every point that would have been a
> question is instead a **Decision Record** below: options, trade-offs, a recommendation with
> reasoning, and a ❓ flag where the call is genuinely the client's (business, legal or UX)
> rather than the engineer's. Review the ❓ items before `/writing-plans`.

---

## Problem Statement

Build the identity, authorization and multi-tenancy backbone for a sports-training platform
on a bare Laravel 13 skeleton. Four roles share one login surface, but the data model has to
express three things the default `User` table cannot:

1. **A tenant boundary.** Every trainer organization is an isolated world. NFR-010 demands
   0% leakage, enforced server-side, while a single player account may legitimately belong to
   several tenants and switch between them.
2. **A person who is not an account.** A child trains but may have no login; a parent both
   owns children and may train themselves (BR-022). "User" and "trainable person" are
   different entities and conflating them is the single biggest modelling risk in this epic.
3. **Constraints that are not roles.** The child deny list (FR-011), tenant membership and
   impersonation guardrails are contextual rules that no role enum can express on its own.

Everything else in the epic — ShareLinks, approvals, availability, impersonation, GDPR —
hangs off getting those three right.

---

## Proposed Solution

A Laravel monolith (Blade + Livewire + Alpine) on the official Livewire starter kit / Fortify,
with four structural commitments:

| Commitment | Mechanism |
|---|---|
| Tenancy is the **default**, not a per-query choice | `BelongsToTenant` trait + **fail-closed** global scope, resolved from session by middleware |
| Identity and personhood are **separate tables** | `User` (credential) ↔ `PlayerProfile` (trainable person), joined by `owner_user_id` / nullable `user_id` |
| Authorization is **enum + Policies**, no package | 4 compile-time roles; the child deny list lives in exactly one array |
| Epic boundaries are **interfaces**, not stubs scattered in code | `ApprovedPurchaseExecutor` is the only seam Epic-05 replaces |

No multi-tenancy package, no permissions package, no impersonation package. Rationale for
each is in the Decision Records — all three are cases where the package's value proposition
(dynamic tenants / runtime-editable permissions / generic impersonation) is not what this
spec asks for, while its cost (a second source of truth) is real.

---

## Architecture

### Layering

```
routes/web.php
  └─ middleware: auth → verified → EnsureTrainerContext → EnforceImpersonationTimeout
       ├─ Livewire components  (interactive screens: tables, grids, forms)
       └─ Controllers          (redirect-only endpoints: /join, context-switch, impersonate)
            └─ Actions         app/Actions/<Domain>/  — one public method, invokable, transactional
                 └─ Services   app/Services/          — stateless helpers, no persistence decisions
                      └─ Models app/Models/           — relationships, casts, scopes; no business logic
```

Conventions per the project's global rules: actions are small and single-purpose, no fat
controllers or fat models; constructor-promoted dependencies use `protected` (never
`private readonly`); comments are one terse line or absent.

Directory plan:

```
app/
  Actions/{Trainer,ShareLink,Family,Approval,Availability,Admin}/
  Enums/            Role, UserStatus, ShareLinkType, CoachStatus, ApprovalStatus, PaymentType
  Contracts/        ApprovedPurchaseExecutor
  Policies/
  Services/         TrainerContext, AvailabilityResolver, CoachConflictChecker, AuditLogger
  Support/Tenancy/  BelongsToTenant, TenantScope
  Livewire/{Admin,Trainer,Family,Availability}/
  Http/Middleware/  EnsureTrainerContext, EnforceImpersonationTimeout, DenyChildAbilities
```

### The two switchers

A recurring source of confusion in the spec is that there are **two** context selectors doing
different jobs. Separating them makes both queries trivial and both testable:

| Switcher | Question it answers | Query |
|---|---|---|
| **Trainer switcher** | *Which organization am I in?* | tenants where any of my profiles has an active `TrainerPlayer` |
| **Profile switcher** | *Which family member am I acting as, here?* | my profiles with an active `TrainerPlayer` **in the current tenant** |

A trainer or coach sees neither (their tenant is fixed and they own one profile). A parent
with one trainer and two children sees only the profile switcher. This resolves the shape of
gap G-08 — see Decision 1.

---

## Decision Records

### Decision 1 — Multi-tenancy mechanism

**Options considered**

| Option | Verdict |
|---|---|
| `stancl/tenancy` | **Reject.** Built for domain/subdomain identification and per-tenant databases. Here one `User` legitimately spans tenants, Super Admin queries across all of them, and there is no subdomain requirement. The package's identification model fights every one of those. |
| Explicit `->where('trainer_profile_id', …)` per query | **Reject as the default.** Correct when remembered; NFR-010 says 0% leakage, and one forgotten `where` is a breach with no failing test to catch it. |
| **Global scope + session-resolved context** | **Recommended.** |

**Recommendation.** A `BelongsToTenant` trait applies a `TenantScope` global scope and
auto-fills `trainer_profile_id` on create. The active tenant comes from a `TrainerContext`
singleton, populated by `EnsureTrainerContext` middleware.

The scope is **fail-closed**: with no resolved tenant it applies `whereRaw('0 = 1')` rather
than returning everything. This inverts the failure mode — a missing context produces an
empty list (a visible bug someone reports) instead of a silent cross-tenant leak. The only
escape is an explicit `->withoutTenantScope()`, which is itself gated on Super Admin.

**Resolution order**, evaluated per request:

1. Trainer → their own tenant, immutable, no switcher rendered.
2. Coach → the tenant of their one active `CoachProfile`, immutable.
3. Player/Parent → `session('trainer_context_id')`, **re-validated on every request** against
   the user's live association set. A session value is never trusted alone: an association
   revoked mid-session must take effect on the next request.
4. No valid choice and several available → redirect to a context picker.
5. Super Admin → no tenant; an explicit "inspect tenant" selection is read-only.

**Not tenant-scoped.** Three data classes, and getting the split right matters more than the
mechanism:

- **Tenant-owned** (scoped, carries `trainer_profile_id`): `ShareLink`, `TrainerPlayer`,
  `CoachProfile`, `CoachAvailabilityOverride`, and later events/tokens/content.
- **Identity** (never scoped): `User`, `PlayerProfile`, `TrainerProfile`, `ImpersonationLog`,
  `UserDeletionLog`, `AuditLog`. A `PlayerProfile` is one person who exists once and is
  *projected into* tenants through `TrainerPlayer`. Scoping it would break the family view.
- **Scoped through its owner**: `Availability`, `PurchaseApproval` — see Decisions 3 and 4.

The consequence to hold onto: a trainer's roster is a query over `TrainerPlayer` (scoped)
joined to profiles — **never** `PlayerProfile::query()`. Reachability of a person inside a
tenant is decided by the association row, not by a column on the person.

**❓ Gap G-08 — what the switcher may reveal.** Proposed rule: the trainer switcher renders
**tenant display name and logo only**. No counts, no badges, no aggregated notifications, no
"3 upcoming sessions at Trainer B". Reasoning: "no unified view" is a rule about *training
data*; the list of organizations the user personally joined is the user's own data. An event
count is a second trainer's data bleeding into the first's context. This gives a rule that is
one sentence long and directly testable — the switcher touches `TrainerPlayer` and
`TrainerProfile.name/logo_path` and no other table.

**Trade-off accepted.** Session-held context means two browser tabs on two trainers fight
each other. The alternative — a URL prefix (`/t/{slug}/…`) — fixes that and makes isolation
legible in the route, at the cost of prefixing every route and complicating the starter-kit
auth routes. For MVP the session matches the spec's wording ("persists across the session")
and costs less; mitigation is that the active tenant is always displayed prominently in the
shell. The URL-prefix migration is the documented upgrade path if multi-tab use is reported.

---

### Decision 2 — Identity model shape

**Recommendation: one `users` table + a role enum + per-role profile tables**, with a sharp
statement of what each table means:

- **`User` = an authentication identity.** Credentials, exactly one `role` (BR-002), status.
  One row per person who can log in.
- **`PlayerProfile` = a trainable person.** Owned by a user (`owner_user_id`); may
  *optionally* have its own login (`user_id`, nullable + unique).

Alternatives rejected: single-table inheritance with nullable role columns (sparse column
sprawl, no integrity); separate `trainers`/`coaches`/`players` auth tables (breaks BR-001's
platform-wide unique email and Fortify's single guard).

**BR-022 (a parent who also trains) needs no special case.** On registration of a
Player/Parent user we always create one **self profile**: `is_child = false`,
`owner_user_id = user.id`, `user_id = user.id`. Children are additional rows with the same
owner and `is_child = true`. "Parent" is therefore **not a role** — it is the emergent state
of owning more than one profile, and the profile switcher's "Me" entry is literally the self
profile. A parent who never trains simply has a self profile with zero associations, so it
never appears in any switcher. No null-checks, no branching, no fifth role.

**Child login.** A child `User` row has `role = player` like any other. How the system knows
it is constrained: a `users.is_child_account` boolean, written by the action that creates or
links a child login. This is *not* a second role — BR-002 governs the permission tier; this
is an orthogonal constraint flag — and keeping it denormalized means every gate check is an
in-memory boolean rather than a join. A test asserts the flag always agrees with the derived
truth (`is_child_account` ⟺ the user backs a `PlayerProfile` with `is_child = true`).

**Ages and G-02.** Store `birth_date`, never a computed age; validate 1–18 on child profiles;
derive age for display. `is_child` and `birth_date` stay independent columns so that relaxing
BR-010 later (letting 16–18-year-olds hold independent accounts, Q-01.05b) becomes a policy
change rather than a migration. MVP implements the strict reading: all under-18 are
parent-managed.

**❓ Skill level (G-10).** Proposal: a nullable string column with the suggestion list in
`config('training.skill_levels')`, so the client can enumerate values later without a
migration and CRM filters work either way. Confirm whether a fixed set exists yet.

---

### Decision 3 — Availability scoping (gap G-04)

US-01.03 requires availability *per trainer per child*; §8 defines availability with no
trainer dimension. Both are defensible in isolation, and picking either alone is wrong:

| Option | Problem |
|---|---|
| Global per profile (§8's shape) | Behaviourally wrong — a child free Monday evenings for their swim trainer may be committed to soccer at the same hour. Contradicts US-01.03. |
| Always per association (US-01.03's shape) | A family with three trainers fills the same grid three times. Availability is *advisory* (BR-019) — high friction for low value. |
| **Default set + optional per-trainer override** | **Recommended.** |

**Recommendation.** `availabilities.trainer_profile_id` is **nullable**:

- `NULL` → the person's default "Best Times", applying in every context.
- non-null → an override set that **wholly replaces** the default for that one trainer.

Wholesale replacement rather than row-level merge keeps the resolution rule to one line and
the UI honest: each context shows either *"Using my default times"* or *"Custom times for
Trainer B"* with a Reset that deletes the override rows. A trainer's "who is free Mon 5–8pm"
filter then has exactly one well-defined answer per player.

This satisfies both source documents: §8's trainer-less record is the default row, and
US-01.03's per-trainer requirement is the override.

**Coach rows** are always non-null (a coach has exactly one trainer), so the table keeps one
consistent meaning: *NULL = applies everywhere*. Because default player rows are NULL,
`Availability` must **not** carry the tenant global scope — it is reached through its owning
profile, and the trainer-side filter joins through the already-scoped `TrainerPlayer`, which
is what preserves isolation. Reads go through an `AvailabilityResolver` service rather than
raw joins; index `(availableFor_type, availableFor_id, trainer_profile_id)` and
`(trainer_profile_id, day_of_week, start_time)` for the CRM filter at NFR-002 scale.

---

### Decision 4 — Purchase approval (gap G-03)

**Recommendation: build the approval domain now, behind one interface.** Deferring it to
Epic-05 would gut this epic — the approval workflow *is* parental control (BR-013/014/015,
US-01.05, US-01.06), and the child deny list is meaningless without something to deny. What
Epic-01 must not do is invent payments.

The seam is a single contract:

```php
interface ApprovedPurchaseExecutor
{
    public function execute(PurchaseApproval $approval): void;
}
```

bound in a service provider to `NullPurchaseExecutor` (records the intent, moves the approval
to `approved`, emits an event) for this epic, and rebound to the Stripe/token implementation
in Epic-05 — a one-line change, with no stub logic scattered through actions.

`PurchaseApproval` carries a nullable polymorphic `approvable` (Epic-02 events fill it later),
`payment_type` (usd/token), `amount_cents`, `status`, request/response timestamps,
`expires_at` and `parent_note`. State machine: `pending → approved | denied | expired`.

**Idempotency is the detail worth writing down.** A double-clicked Approve must not become a
double charge when Epic-05 lands. Every transition is a conditional update inside a
transaction — `where('id', …)->where('status', 'pending')->update(…)` — and the executor runs
**only if that update affected a row**. Get this right now and the payment integration
inherits it for free.

**Token bypass (BR-014)** lives as one decision in one action:
`player_profiles.token_spend_requires_approval` defaults to `true`; when it is `false` and
`payment_type = token`, `RequestPurchaseApprovalAction` short-circuits — no approval row,
immediate execution, informational notification to the parent. Not an `if` scattered across
the codebase.

**Expiry** is a scheduled `ExpirePurchaseApprovalsJob` sweeping `pending` rows past
`expires_at` (48 h, NFR-009) into `expired` with notification.

**Honest scoping.** Slice C ships the parent approval queue, the state machine, the
notifications and the expiry job, fully tested against a test double. It does **not** ship a
checkout UI — there is nothing to buy until Epic-02.

---

### Decision 5 — Authorization strategy

**Recommendation: `Role` enum + Policies/Gates. No permissions package.**

`spatie/laravel-permission` earns its place when roles and permissions are *dynamic* —
assigned at runtime, edited by admins, varying per installation. Here BR-002 fixes exactly
four roles known at compile time, and no requirement anywhere mentions custom roles or a
permission editor. The package would add three tables, a cache layer and a second source of
truth for every check — and it still could not express the two rules that actually matter
here, tenant membership and the child deny list, because both are contextual rather than
role-static. Cost real, benefit unused.

**Revisit trigger, recorded now:** if the client later asks for trainer-defined custom
permissions for coaches, that is the signal to reconsider.

Shape:

- `Role` backed enum with behaviour on it (`label()`, `dashboardRoute()`), `UserStatus` enum.
- One policy per model. Each policy asks, in order: **tenant membership → role → child deny
  list**. Tenancy first, because a check that passes on role but not tenancy is exactly the
  NFR-010 breach.
- `Gate::before` grants Super Admin — but **only when no impersonation is active**. While
  impersonating, the acting identity is the target; an admin id leaking into the gate would
  be a privilege hole hiding behind a support feature.
- **The child deny list lives in exactly one array.** A `ChildAbilities::DENIED` constant
  enumerates FR-011's forbidden abilities; a `Gate::before` hook returns `false` (fail-closed,
  short-circuiting) for any of them when `is_child_account`. One file to audit, and one test
  that iterates the array and asserts each entry is refused. UI hiding stays cosmetic.

---

### Decision 6 — Impersonation mechanics (gap G-12)

**Recommendation: custom, roughly 120 lines.** `lab404/laravel-impersonate` is the obvious
candidate, but the requirements here — 1-hour expiry, an audit log with duration, the
Super-Admin-on-Super-Admin block, a banner, and dual-identity attribution — are mostly what
the package does *not* provide, so it would be wrapped in as much code as it replaces.
❓ Flagged in case the team prefers the package as a base.

**Start.** `POST /admin/impersonate/{user}` — POST and CSRF-protected; a GET impersonation
route would be trivially CSRF-exploitable. Gate: actor is Super Admin, target is **not** Super
Admin (BR-016), target is active, no impersonation already running.

**Session mechanics, with the trap named.** Write `impersonator_id`,
`impersonation_log_id`, `impersonation_started_at`, then `Auth::login($target)`. Never call
`Auth::logout()` first — logout flushes the session and would destroy the very keys needed to
get back. `Auth::login()` regenerates the session id while preserving session data, which is
the behaviour we want.

**Expiry.** An `EnforceImpersonationTimeout` middleware on the web group compares
`impersonation_started_at` to now and force-stops past 60 minutes — restoring the admin,
closing the log, redirecting with a notice. Expiry is necessarily *passive*: no scheduled job
can reach into a live session. Because an admin may simply abandon the tab, a nightly
`CloseStaleImpersonationLogsJob` closes any log still open past the timeout ceiling, so the
compliance report never contains open-ended rows.

**Stop and restore — the G-12 answer.** `POST /impersonate/stop` re-authenticates the
impersonator by id and clears the keys. The admin's original session is **not** held as a
second parallel session; we re-login the same id. Consequences, stated so they surprise
nobody: unsaved admin UI state is lost on both transitions, the admin's "remember me" is
untouched, and if the admin's own account is deactivated mid-impersonation, stopping fails
closed to the login screen.

**Attribution — the other half of G-12.** Every audited write during impersonation records
**both** identities: `actor_user_id` (the target, who appears in the data) and
`on_behalf_of_user_id` (the admin). A single `AuditLogger` service reads
`session('impersonator_id')`, and a middleware pushes the same value into the Monolog context
so even framework-level log lines carry it.

**❓ Proposed guardrail beyond the spec.** The spec says permissions match the target exactly
and does not forbid writes. Recommend allowing writes but hard-denying a short list while
impersonating: changing the target's password or email, deleting the account, and any payment
action. Support staff should not be able to take over an account permanently or spend a
customer's money — this is a small addition with a large security return. Needs sign-off
since it deviates from "matches the impersonated user exactly".

---

### Decision 7 — GDPR deletion (gap G-05)

§8 asks for a "backup of original data for legal compliance" while US-01.13 requires
irreversible anonymization. These cannot both hold, and the way out is not a compromise on
irreversibility.

**Reasoning.** GDPR Art. 17(3) permits retention only where a *specific* ground applies —
legal obligation, or the establishment/exercise/defence of legal claims. An indefinite full
backup of the original record for unspecified reasons defeats the erasure and would not
survive scrutiny. A minimal, purpose-justified record does.

**Recommended retained minimum** — `user_deletion_logs`:

| Column | Justification |
|---|---|
| `original_user_id` | Historical rows still point at it; without it the surviving records are unreadable |
| `email_hash` | Salted hash, **not** the address |
| `deleted_by_user_id`, `deleted_at`, `reason` | Accountability for the erasure itself |

**No payload column.** The "backup of original data" is dropped.

**The email is hashed rather than stored in clear** — an improvement over the requirements
draft. The only genuine operational need is *proving* an address was erased on a date, or
recognising a re-registering person, and a hash answers both: hash the enquirer's address and
compare. Retaining the readable identifier buys nothing extra and is precisely what Art. 17
asks you to remove. ❓ If counsel insists on clear text, it should carry an explicit TTL.

The log rows themselves get a retention period (proposal: 6 years, configurable to match the
contractual limitation period) after which a scheduled job purges them. Erasure with no
horizon is not erasure.

**Anonymization mapping** — one transaction, log row written **before** the scrub so a
mid-failure cannot lose the record:

| Field | After |
|---|---|
| `first_name` / `last_name` | "Deleted" / "User" |
| `email` | `deleted_{id}@deleted.invalid` — `.invalid` is RFC 2606 reserved and guaranteed never to resolve. The spec's `example.com` is also RFC 2606 reserved but is a live domain operated by IANA; `.invalid` removes any possibility of delivery |
| `password` | a fresh random hash, never null — fails closed |
| `phone`, `photo_path`, `birth_date`, `school`, `jersey_number`, `emergency_contact` | null; the stored photo file deleted from disk |
| `remember_token`, sessions, password-reset tokens | cleared |
| `status` | `deleted` |

**❓ Owned child profiles.** A child's name is PII belonging to the deleted family, and the
spec does not address it. Recommend anonymizing owned `PlayerProfile` rows by the same
mapping. Confirm.

**Survives**: `TrainerPlayer` rows, attendance, RSVPs and payment history, rendering as
"Deleted User" — analytics aggregates unchanged (BR-018). Reactivation is impossible, enforced
in two places: `status = deleted` blocks login, and `ReactivateUserAction` refuses that status.

---

## Data Model

### Tables

**`users`** (extends the default migration) — `email` unique, `password`, `role` enum,
`status` enum, `is_child_account` bool default false, `first_name`, `last_name`, `phone`,
`photo_path`, `email_verified_at`, `last_login_at`, timestamps.
Index: `(role, status)` for the directory filter at NFR-002 scale.

**`trainer_profiles`** — `user_id` unique, `business_name`, `slug` unique, address/website/
description, `logo_path`, `primary_color`. The tenant root.

**`coach_profiles`** — `user_id`, `trainer_profile_id`, `status` enum (invited/active/
inactive), bio, credentials, certifications, `is_public`, `joined_at`.

> **BR-006 enforcement on MariaDB.** Partial unique indexes do not exist, so use a generated
> column: `active_user_id = IF(status = 'active', user_id, NULL)` with a unique index on it.
> NULLs do not collide in a MariaDB unique index, so this permits many inactive historical
> rows and at most one active association per coach — enforced by the database, not by hope.
> The `CoachInvitationAction` additionally takes `lockForUpdate` on the user's coach rows.

**`player_profiles`** — `owner_user_id`, `user_id` nullable unique (the optional child login),
`name`, `birth_date`, `gender`, `skill_level` nullable, `school`, `jersey_number`, `is_child`,
`emergency_contact`, `token_spend_requires_approval` default true.

**`trainer_players`** — `trainer_profile_id`, `player_profile_id`, `share_link_id` nullable,
`connected_at`, `status`, `deleted_at` (soft delete preserves history per FR-009).
Unique `(trainer_profile_id, player_profile_id)` among non-deleted rows.

**`share_links`** — `code` unique, `type` enum, `trainer_profile_id`, `created_by_user_id`,
`target_email` nullable, `expires_at` nullable, `max_uses` nullable, `uses_count`, `is_active`.
Player links: unlimited, no expiry (BR-008). Coach links: single use, 7 days (BR-009).

**`availabilities`** — polymorphic `availableFor`, `trainer_profile_id` **nullable**,
`day_of_week`, `start_time`, `end_time`, `is_available`.

**`purchase_approvals`** — `player_profile_id`, `parent_user_id`, nullable polymorphic
`approvable`, `payment_type`, `amount_cents`, `status`, `requested_at`, `responded_at`,
`expires_at`, `parent_note`. Index `(status, expires_at)` for the sweeper.

**`impersonation_logs`**, **`coach_availability_overrides`**, **`user_deletion_logs`**,
**`audit_logs`** (`actor_user_id`, `on_behalf_of_user_id`, `action`, polymorphic `subject`,
`ip_address`, `metadata` json).

### Race-safety notes

- **ShareLink redemption** runs inside a transaction with `lockForUpdate` on the link row, so
  the `uses_count` increment and single-use enforcement hold under NFR-004's 100 concurrent
  registrations. Without the lock, two simultaneous redemptions of a coach link both succeed.
- **Approval transitions** use the conditional-update pattern from Decision 4.
- **Coach activation** relies on the generated-column unique index above.

### Factories and seeders

Factories for every model. A `DemoSeeder` builds the hard scenario end to end: two trainers,
a parent with a self profile plus two children, one child holding their own login, one child
associated with both trainers with a per-trainer availability override, and one pending
purchase approval. If a change breaks isolation, this seeder plus the isolation tests catch it.

---

## Authentication Flow

The starter kit gives login, registration, password reset and email verification via Fortify.
Three deliberate deviations:

**Registration is not open.** BR-003 forbids trainer self-registration, and player
registration only ever happens *through* a ShareLink. Left as scaffolded, the route lets
anyone create an unassociated account. Recommendation: keep `Features::registration()`
enabled but gate the route on a valid ShareLink code placed in the session by `/join/{code}`;
a bare hit on `/register` redirects to a "you need an invitation" page. **This is a real hole
the requirements did not call out.**

**Inactive and deleted accounts.** A custom Fortify authentication pipeline step returns the
exact copy *"Account deactivated. Contact support."* (FR-017) while keeping rate limiting
(NFR-007) in front of it, so the message does not become an account-enumeration oracle for
unthrottled probing.

**❓ Email verification (Q-01.05a).** Recommendation: verification required to *act*, not to
log in — `verified` middleware on everything except the profile page and the verification
notice. A ShareLink registrant who cannot get in at all will abandon; blocking actions
achieves the security goal at a fraction of the drop-off. Needs client confirmation.

**❓ Session lifetime (Q-01.07).** Recommendation: 7 days rolling
(`SESSION_LIFETIME=10080`, `expire_on_close=false`) — the usual balance for a consumer sports
app used mostly on phones.

---

## Library vs Custom Decisions

| Need | Recommendation | Why |
|---|---|---|
| Auth scaffolding | **Livewire starter kit / Fortify** | First-party; `laravel/breeze` is legacy |
| Multi-tenancy | **Custom scope** (no `stancl/tenancy`) | Users span tenants; no subdomains — see Decision 1 |
| Permissions | **Enum + Policies** (no `spatie/laravel-permission`) | 4 compile-time roles — see Decision 5 |
| Impersonation | **Custom** (no `lab404/laravel-impersonate`) ❓ | Expiry + audit + attribution are the bulk of the work either way |
| Image resizing | **`intervention/image`** | Standard, maintained; do not hand-roll GD |
| Audit trail | **Custom `audit_logs`** (no `spatie/laravel-activitylog`) | Three of NFR-011's four audited operations already have purpose-built report tables; the package's automatic model-event logging is not requested |
| Validation, queues, notifications, mail, scheduling | **Framework built-ins** | Form Requests / Livewire rules, queue, Notifications, scheduler |

---

## Error Handling

| Case | Behaviour |
|---|---|
| Duplicate email on trainer creation | Field-level validation error naming the conflict; never a 500 |
| Expired / used / inactive ShareLink | Dedicated `/join` state per cause, with a "request a new link" CTA — not a generic 404 |
| Coach accepts a second invitation | Explicit rejection naming the current trainer (BR-006) |
| Child clicks a ShareLink | No association; child sees *"Ask your parent to register you with this trainer"*; parent emailed the link with a Review CTA (FR-011) |
| Denied ability (child or role) | 403 server-side; UI hiding is cosmetic only |
| Missing tenant context | Empty result + redirect to the context picker — never an unscoped read |
| Approval acted on twice | Conditional update affects 0 rows → "already resolved" notice, no second execution |
| Impersonation timeout mid-request | Force-stop, restore admin, flash notice |
| Logo/photo upload rejected | Type and size validated before the disk write; the resize failure path deletes the partial upload |

---

## Testing Strategy

PHPUnit 12 (Pest is not installed), feature-tests first — the risks in this epic are
integration risks, not unit ones.

**Isolation matrix (the highest-value suite).** For each tenant-owned model, assert that
trainer A can neither read, list, nor mutate trainer B's rows, through the HTTP layer with a
real session — including the case where trainer A guesses a valid id belonging to B.

**Deny-list iteration.** One test loops `ChildAbilities::DENIED` and asserts each entry is
refused for a child account. Adding an ability without adding a test becomes impossible.

**Fail-closed scope.** Assert directly that a tenant-scoped query with no resolved context
returns zero rows rather than everything. This is the test that protects NFR-010's mechanism
rather than its symptoms.

**Journeys.** ShareLink registration (new and existing account); multi-trainer association;
the parent-with-children selection prompt; child requests purchase → parent approves; coach
invite expiry and single use; impersonation start/exit/timeout/logging; deactivation
preserving history; deletion anonymizing PII while aggregates hold.

**Unit.** Availability resolution (default vs override), approval state machine including
double-approve idempotency, coach conflict matrix, anonymization mapping asserted field by
field against an explicit expectation table.

**Concurrency.** Parallel redemption of a single-use coach link resolves to exactly one
acceptance.

**Livewire component tests** for the two switchers, the availability grid and the approval queue.

---

## Security Considerations

- Tenancy fail-closed by default; `withoutTenantScope()` gated on Super Admin (NFR-010).
- Rate limiting on login and password reset (NFR-007); CSRF on all state-changing requests
  including impersonation start/stop (NFR-008).
- Token TTLs per NFR-009: verification 24 h, reset 1 h, impersonation 1 h, coach link 7 days,
  approval 48 h.
- Authorization enforced server-side in policies; UI hiding never load-bearing (FR-004).
- Impersonation: no Super-Admin-on-Super-Admin, dual-identity attribution, passive timeout,
  and the ❓ proposed write guardrail.
- **❓ SVG logo uploads (FR-019).** The spec permits SVG, which is an XSS vector — an SVG is a
  document that can carry script, and it will be served to every user in that trainer's
  organization. Recommend restricting MVP to PNG/JPG, or sanitizing through a strict whitelist
  before storage. Needs a product call.
- Uploads validated on MIME and size before the disk write; user files stored on a configured
  disk outside the webroot and served through a signed route.
- Passwords via the framework's default hashing, never reversible (NFR-006).

---

## Delivery Slices

Unchanged A→D from the requirements, with this design's additions placed:

| Slice | Adds |
|---|---|
| **A** | Starter kit; enums; extended users migration; profiles; policies + `ChildAbilities`; gated registration; deactivation-aware login; users directory; own-profile editing |
| **B** | `BelongsToTenant` + `TenantScope` + `EnsureTrainerContext`; ShareLinks with locked redemption; associations; coach invites with the generated-column constraint; both switchers |
| **C** | Child profiles and child logins; family view; association add/remove; approval domain + `NullPurchaseExecutor`; expiry job; notifications |
| **D** | Availability (default + override) and the coach conflict checker; impersonation with attribution and timeout; deactivate/reactivate; anonymization + deletion log; branding |

---

## Open Questions

Carried from the requirements analysis, plus what this design surfaced. The ❓ items block
`/writing-plans` for the slice named.

| ID | Question | Proposed answer | Blocks |
|---|---|---|---|
| G-02 / Q-01.05b | May 16–18-year-olds hold independent accounts? | MVP: no. `is_child` + `birth_date` kept independent so relaxing it is a policy change | C |
| G-05 | Retained minimum on deletion | Hashed email, no data payload, 6-year log TTL | D |
| G-08 | What may the trainer switcher reveal? | Name and logo only; no counts or badges | B |
| G-10 / Q-01.01 | Skill level values | Free text + a config suggestion list | A |
| G-11 | Coach transfer between trainers | Explicit release by trainer A, history retained, DB-enforced single active | B |
| G-12 | Write guardrails during impersonation | Deny credential changes, account deletion and payments | D |
| Q-01.04 | Full list of automated emails | The eight in the requirements draft | A–D |
| Q-01.05a | Verification before login? | Required to act, not to log in | A |
| Q-01.07 | Session lifetime | 7 days rolling | A |
| — | Child profile PII on parent deletion | Anonymize owned child profiles too | D |
| — | SVG logo uploads | Restrict to PNG/JPG for MVP, or sanitize | D |
| — | Impersonation: custom or package base | Custom | D |
| G-01 | Camp-to-User conversion (FR-020) | Deferred — no acceptance criteria, Epic-08 unscheduled | — |
| G-03 | Approval domain now or later? | **Resolved by Decision 4** — build now behind `ApprovedPurchaseExecutor` | C |
| G-04 | Availability trainer scope | **Resolved by Decision 3** — nullable default + override | D |
| G-06 / G-07 / G-09 | Spec cross-reference defects | Editorial fixes in the source spec; no code impact | — |
