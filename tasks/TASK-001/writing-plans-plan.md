# Epic-01 Slice A — Implementation Plan

**Task**: TASK-001
**Scope**: Slice A only — auth, RBAC, identity tables, user directory, profile editing.
Slices B–D get their own plans; this one is the smallest shippable milestone and unblocks the rest.

## Goal

A running application where a seeded Super Admin logs in, sees a paginated user directory, and creates Trainer accounts that receive an invitation email; every role lands on its own dashboard; everyone edits their own profile; non-active accounts cannot log in; and **no self-registration exists**.

## Existing Context

The repository is a bare `laravel/laravel` skeleton — no auth, no domain code.

| File | Current state | Impact |
|---|---|---|
| `routes/web.php` | One closure returning `welcome` | Replaced wholesale |
| `app/Models/User.php` | Default. Laravel 13 uses **PHP attributes** — `#[Fillable([...])]`, `#[Hidden([...])]` — not `$fillable`/`$hidden` properties | New columns are added to the attribute, not to a property |
| `database/migrations/0001_01_01_000000_create_users_table.php` | `name`, `email`, `password`, `remember_token`; also creates `password_reset_tokens` and `sessions` | Extended by a new migration, never edited in place |
| `database/migrations/0001_01_01_000002_create_jobs_table.php` | Present | `database` queue driver works out of the box |
| `phpunit.xml` | `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` | **Blocking conflict — see Step 0** |
| `composer.json` | `laravel/pint` in require-dev; scripts `setup`, `dev`, `test` | `vendor/bin/pint` available; **no PHPStan/Larastan installed** |
| `tests/` | `TestCase.php` + two `ExampleTest` stubs | Clean slate |
| `.ddev/config.yaml` | Laravel type, PHP 8.4, MariaDB 11.8, Node 24 | All commands run inside DDEV |

## Corrections To Earlier Documents

Two assumptions carried from the design and architecture documents do not survive contact with the repository. Both change what the implementer types.

**1. The official starter kit cannot be installed here.** Laravel 13's starter kits are *project templates* (`laravel new --livewire`), not a package you add to an existing application the way `laravel/breeze` was. This repository already exists and carries committed accelerator tooling, so scaffolding over it is not an option.

Replacement: install the same underlying pieces directly — `composer require laravel/fortify livewire/livewire`, then `php artisan fortify:install` (publishes `app/Actions/Fortify/`, `FortifyServiceProvider`, `config/fortify.php`, migrations) followed by a migrate. This is the documented path for adding Fortify to an existing app, and it is what the starter kit wraps. Every architecture decision that referenced "the starter kit" (AD-004, the auth flow) is unaffected — Fortify is the part those decisions actually depend on.

**2. Tests cannot run on SQLite.** `phpunit.xml` points at `sqlite::memory:` while the application runs MariaDB 11.8, and BR-006's enforcement depends on a MariaDB generated column (`IF(status='active', user_id, NULL)` plus a unique index). `IF()` is MySQL/MariaDB syntax; the migration will not run on SQLite. The engines also diverge precisely on this feature — SQLite has partial indexes, MariaDB does not, which is *why* the generated column exists.

Running the suite on SQLite would mean the database-level invariant the architecture leans on is never exercised, and the migration itself would fail the moment Slice B lands. Fix it in Step 0, before any schema work.

## Assumptions

Recorded rather than blocking; each is a config-level or nullable-column decision that is cheap to reverse.

| Open item | Assumed | Reversal cost |
|---|---|---|
| Q-01.05a — email verification | Required to **act**, not to log in: `verified` middleware on everything except the profile page and the verification notice | Move one middleware entry |
| Q-01.07 — session lifetime | 7 days rolling (`SESSION_LIFETIME=10080`, `expire_on_close=false`) | `.env` change |
| G-10 — skill levels | Nullable string column, suggestion list in `config/training.php` | None until a fixed set exists |
| UI primitives | `livewire/flux` free tier for accessible form/table primitives (NFR-012). Flux **Pro is not required for Slice A** | Swap for plain Blade components |

## Proposed Design

**Routes** (`routes/web.php`, all `auth` + `verified` unless noted):
- `GET /dashboard` → `DashboardController` — redirects to the role's home
- `GET /profile` → `Livewire\ProfileForm` (**not** `verified`)
- `GET /admin/users` → `Livewire\Admin\UsersTable` (Super Admin)
- `GET /admin/users/create` → `Livewire\Admin\CreateTrainerForm` (Super Admin)
- Fortify owns login, logout, password reset and email verification. **Registration is disabled** — `Features::registration()` removed from `config/fortify.php`. Slice B introduces the only registration surface at `/join/{code}`.

**Authorization**: `Role` enum + one policy per model, checked via `$this->authorize()`. `Gate::before` grants Super Admin only when no impersonation is active (the check is written now; impersonation arrives in Slice D). `ChildAbilities::DENIED` and its `Gate::before` hook are created in Slice A so Slice C has nowhere to scatter child rules.

**No tenancy yet.** `BelongsToTenant`, `TenantScope` and `EnsureTrainerContext` belong to Slice B. Slice A touches only identity tables, which AD-001 classifies as never scoped — so nothing built here needs retrofitting.

---

## Implementation Steps

### Step 0 — Move the test suite onto MariaDB *(blocking prerequisite)*

- Create a `test` database in the DDEV `db` service.
- In `phpunit.xml` replace the `DB_CONNECTION`/`DB_DATABASE` env entries with the MariaDB test connection; keep `CACHE_STORE=array`, `MAIL_MAILER=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`.
- Add `RefreshDatabase` to `tests/TestCase.php` or per test class; migrations then run against the real engine.
- Delete the two `ExampleTest` stubs.

**Verify**: `ddev exec php artisan test` runs green against MariaDB with zero tests.
**Risk if skipped**: every subsequent schema step is validated against the wrong engine.

### Step 1 — Fortify + Livewire, registration disabled

- `ddev composer require laravel/fortify livewire/livewire livewire/flux`
- `ddev exec php artisan fortify:install`, then run migrations.
- `config/fortify.php`: remove `Features::registration()`; keep `resetPasswords()` and `emailVerification()`; leave two-factor out (explicitly Post-MVP).
- `FortifyServiceProvider`: register `loginView`, `requestPasswordResetLinkView`, `resetPasswordView`, `verifyEmailView`. Write the Blade views under `resources/views/auth/`.
- Configure the login rate limiter (NFR-007) and set `SESSION_LIFETIME=10080` in `.env` and `.env.example`.

**Verify**: feature test — a factory user logs in and reaches `/dashboard`; `GET /register` returns 404; a bad-password login is throttled after the configured attempts.

### Step 2 — Enums and the users table extension

- `app/Enums/Role.php` (`super_admin`, `trainer`, `coach`, `player`) with `label()` and `dashboardRoute()`; `app/Enums/UserStatus.php` (`active`, `inactive`, `deleted`).
- Migration `extend_users_table`: `role`, `status` (default `active`), `is_child_account` bool default false, `first_name`, `last_name`, `phone`, `photo_path`, `last_login_at`; index `(role, status)` (AD-012).
- `app/Models/User.php`: extend the `#[Fillable]` attribute, add `role`/`status` casts to `casts()`, add a `name` accessor composing first + last. Keep the legacy `name` column for one slice or drop it in the same migration — decide once and state it in the migration comment.
- Update `UserFactory` with role/status states.

**Verify**: unit test asserting enum casts round-trip; factory produces each role.

### Step 3 — Profile tables

- Migrations + models for `trainer_profiles` (user_id unique, business_name, slug unique, address, website, description, logo_path, primary_color), `coach_profiles` (user_id, trainer_profile_id, status, bio, credentials, certifications, is_public, joined_at), `player_profiles` (owner_user_id, user_id nullable unique, name, birth_date, gender, skill_level nullable, school, jersey_number, is_child, emergency_contact, token_spend_requires_approval default true).
- Relationships on `User`: `trainerProfile()`, `coachProfile()`, `ownedPlayerProfiles()`, `playerProfile()`.
- Factories for all three.
- `config/training.php` with the skill-level suggestion list.

**Note**: `coach_profiles.trainer_profile_id` exists now but carries no tenant scope until Slice B. The BR-006 generated column also lands in Slice B, with coach invitations.

**Verify**: unit tests for each relationship; a full migration run from an empty database succeeds.

### Step 4 — Role dashboards and routing

- `DashboardController` redirecting via `Role::dashboardRoute()`; a placeholder Blade view per role.
- Rewrite `routes/web.php`: root redirects to `/dashboard` when authenticated, to login otherwise.
- Apply `verified` to the authenticated group, exempting `/profile` and Fortify's verification routes (Q-01.05a assumption).

**Verify**: feature test — each of the four roles lands on its own dashboard and receives 403 on the other three.

### Step 5 — Policies, ChildAbilities, Gate wiring

- `app/Support/Tenancy/ChildAbilities.php` — a `DENIED` constant array holding FR-011's forbidden abilities.
- `AppServiceProvider::boot()`: `Gate::before` granting Super Admin **only** when `session('impersonator_id')` is absent, and a second `Gate::before` returning `false` for any denied ability when `is_child_account`.
- `UserPolicy`, `TrainerProfilePolicy`, `CoachProfilePolicy`, `PlayerProfilePolicy` — each evaluating tenant membership → role → child deny list. The tenancy branch is a documented no-op stub until Slice B, so the ordering is fixed now and never retrofitted.

**Verify**: a test that iterates `ChildAbilities::DENIED` asserting every entry is refused for a child account; policy tests per role.

### Step 6 — Users directory

- `app/Livewire/Admin/UsersTable.php` — server-side pagination, role and status filters, debounced tool-scoped search over name and email; `simplePaginate()` unless exact page counts are required (AD-012).
- Row actions render as disabled placeholders where the underlying action ships in Slice D (impersonate, deactivate, delete).

**Verify**: feature test — a non-Super-Admin gets 403; filters and search return the expected subset; a seeded large dataset is asserted to issue a bounded number of queries (guards NFR-002 without asserting wall-clock time).

### Step 7 — Create trainer account

- `app/Actions/Trainer/CreateTrainerAccount.php` — one `DB::transaction()` creating the `User` (role `trainer`, status `active`) and its `TrainerProfile`; dispatches `TrainerInvitationNotification` **after commit** (AD-007); writes an audit entry.
- `TrainerInvitationNotification implements ShouldQueue` — a signed, expiring password-setup link rather than a temporary password in an email.
- `app/Livewire/Admin/CreateTrainerForm.php` with validation: unique email, required business name / trainer name / phone.
- `app/Services/AuditLogger.php` + `audit_logs` migration (`actor_user_id`, `on_behalf_of_user_id` nullable, `action`, polymorphic `subject`, `ip_address`, `metadata` json). `on_behalf_of_user_id` exists from the start so Slice D's impersonation attribution needs no migration.

**Verify**: feature test — creation persists both rows, queues the notification, writes the audit row, and rejects a duplicate email with a field-level error (never a 500); `Notification::fake()` asserts the setup link is signed and expiring.

### Step 8 — Deactivation-aware login

- A `Fortify::authenticateThrough()` pipeline placing a custom `EnsureAccountIsActive` step **after** `EnsureLoginIsNotThrottled` and `AttemptToAuthenticate`, returning the exact FR-017 copy *"Account deactivated. Contact support."*
- Update `last_login_at` on the `Login` event.

**Verify**: feature tests — inactive and deleted users are refused with the exact message; the throttle still fires first for repeated attempts, so the message cannot be probed as an enumeration oracle.

### Step 9 — Own-profile editing

- `app/Livewire/ProfileForm.php` — common fields plus the role-specific set from FR-016; email, role, skill level and created-at rendered read-only.
- `ddev composer require intervention/image`; photo upload validated on MIME and size **before** the disk write, stored on a non-public disk and served through a signed route; the resize failure path deletes the partial upload.

**Verify**: feature tests — a user updates their own profile; a user cannot update another's (403); an oversized or wrong-MIME upload is rejected and leaves no file on disk.

### Step 10 — Seeder and green suite

- `DemoSeeder`: one Super Admin, two Trainers with profiles, one Coach, one parent with a self profile and two children (one holding its own login). The multi-trainer and availability parts of the scenario arrive with Slices B and D.
- Run the full suite plus Pint.

**Verify**: a seeded rebuild from an empty database succeeds; `ddev composer test` green; `ddev exec vendor/bin/pint --test` clean.

---

## Test Plan

**Feature** (the bulk — the risks here are integration risks):
- Login success, failure, throttling, inactive and deleted refusal.
- `GET /register` returns 404; no route creates an account outside `CreateTrainerAccount`.
- Password reset and email-verification round trips.
- Role → dashboard mapping, and cross-role 403s.
- Users directory authorization, filtering, search, pagination and query-count bound.
- Trainer creation: persistence, queued notification, audit row, duplicate-email validation.
- Profile editing: own yes, another's no; upload validation.

**Unit**:
- `Role` and `UserStatus` enum behaviour.
- `ChildAbilities::DENIED` iteration (one test, grows automatically with the array).
- `AuditLogger` field mapping including a null `on_behalf_of_user_id`.

**Policy**: every policy method against all four roles plus a child account.

## Verification

```sh
ddev exec php artisan test          # or: ddev composer test
ddev exec vendor/bin/pint --test    # laravel/pint is installed
```

Plus a rebuild of the schema with seed data before the final review.

**Static analysis is not currently available** — no PHPStan, Larastan or Psalm is installed and no config exists. Adding `larastan/larastan` at level 5 is worth a separate decision; do not claim a static-analysis gate until it is actually installed.

## Risks

| Risk | Mitigation |
|---|---|
| **Test suite on the wrong engine** (Step 0) — MariaDB-specific DDL fails or, worse, silently differs | Fixed before any schema work; migrations exercised against the real engine from step one |
| **Fortify into an existing app** — published files may collide with committed accelerator tooling | Run `fortify:install` on a clean working tree and review the diff before committing; the publish is reversible by revert |
| **`users.name` legacy column** — the default migration ships it and Fortify's `CreateNewUser` writes it | Decide in Step 2 whether to drop it or keep it as a display column; do not leave it ambiguous for Slice B |
| **`is_child_account` denormalized** | Written by exactly one action; invariant test asserts agreement with the backing profile |
| **Policy tenancy branch is a stub in Slice A** | The branch exists and is ordered first now, so Slice B fills it in rather than inserting it — the retrofit that would otherwise cause an NFR-010 gap |
| **Flux licensing** | Only the free tier is used in Slice A; confirm terms before any Pro component enters Slice D's availability grid |
| **Queue and scheduler not running** (AD-008) | `QUEUE_CONNECTION=sync` in tests; DDEV must run a worker for local parity, or invitation emails silently never send |
