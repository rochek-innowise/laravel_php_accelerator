---
description: Implementation plan for Epic-01 Slice D — availability, Super Admin impersonation, deactivate/reactivate, GDPR anonymization, and trainer portal branding. Closes Epic-01.
---

# Epic-01 Slice D — Implementation Plan

**Task**: TASK-001
**Scope**: Slice D only — FR-014 (player/parent availability), FR-015 (coach My Times + conflict
override log), FR-012 (Super Admin impersonation), FR-017 (deactivate/reactivate), FR-018 (GDPR
delete), FR-019 (trainer branding). This closes Epic-01; no later epic is touched.

**Depends on**: Slices A, B and C, complete and green on `main`. Slice C's migrations already
created `purchase_approvals`, `notifications`, and `player_profiles.photo_path`; Slice A's
`audit_logs` migration already carries `on_behalf_of_user_id`, and `trainer_profiles` already
carries `logo_path`/`primary_color` — three things this slice would otherwise need to add, and
does not.

## Goal

A Super Admin can impersonate a user (never another Super Admin, never a second impersonation
stacked on top of one already running), see a sticky colour-coded banner naming who they are
viewing as, and every write during that session is attributed to both the target and the admin.
The session force-expires after 60 minutes even if never explicitly stopped, and a nightly sweep
closes any log left open by an abandoned tab. A Super Admin can deactivate a user (blocking login,
preserving history) and reactivate them, or permanently anonymize a user's PII (GDPR), which also
scrubs any child profile the deleted user was the sole guardian of — irreversibly, logged with a
salted email hash rather than the address itself. A player or parent sets a weekly "Best Times"
grid per child, and can optionally override it for one specific trainer without touching the
default; a coach sets one weekly schedule (always trainer-specific, since a coach has exactly one
employer). A trainer sets a logo and primary colour that apply immediately across their
organisation's shell. FR-015's event-assignment UI and FR-014's CRM filter screen both belong to
epics that do not exist yet (Epic-02, Epic-03); this slice ships the model, the resolver, the
conflict-checking service, and the query the future screens will call, fully tested against
direct callers, and says exactly where the unbuilt seam is.

## Existing Context

| File | Current state | Impact in Slice D |
|---|---|---|
| `app/Policies/UserPolicy.php` | `impersonate()`, `deactivate()`, `delete()` **already implemented** in Slice A, ahead of this slice — BR-016 (no Super-Admin-on-Super-Admin), active-only target, never-self | Reused for `deactivate`/`delete`; `impersonate()` gets one addition (below) and a new `reactivate()` method is added |
| `app/Providers/AppServiceProvider.php` | `NOT_BYPASSABLE = ['impersonate']` and a `Gate::before` that refuses the Super-Admin bypass while `isImpersonating()` **already exist**, anticipating this slice | Reused verbatim; two additions — the `user.change-credentials` Gate and a new `Gate::before` for `ImpersonationGuardrail` (see Decisions) |
| `app/Services/AuditLogger.php` | `log()` already sets `actor_user_id = auth()->id()` and `on_behalf_of_user_id = session('impersonator_id')` | **No change.** Once `StartImpersonation` writes `impersonator_id` into the session before `Auth::login($target)`, every subsequent `AuditLogger::log()` call is already dual-attributed correctly — this is the single chokepoint FR-012's attribution requirement asks for, and it was built two slices early |
| `database/migrations/2026_09_01_132257_create_audit_logs_table.php` | Comment: *"on_behalf_of_user_id exists from day one so Slice D's impersonation attribution needs no migration"* | Confirmed — no migration touches `audit_logs` in this slice |
| `database/migrations/2026_09_01_132254_create_trainer_profiles_table.php` | `logo_path` (nullable string), `primary_color` (nullable, 7 chars) already columns | No migration for branding; `TrainerProfile`'s `#[Fillable]` already includes both, so `UpdateTrainerBranding` needs no `forceFill` either |
| `app/Policies/TrainerProfilePolicy.php` | `updateBranding()` **already implemented** (owner-only) | Reused as-is — the only policy FR-019 needs |
| `app/Actions/Profile/StoreProfilePhoto.php` | Handles disk write, MIME-sniff-then-decode validation, and old-file cleanup for `User`/`PlayerProfile`; its own docblock names FR-018 as "exactly the consumer that would purge a whole owner directory" | `AnonymizeUser` calls `StoreProfilePhoto::remove()` for the target and for each anonymized child profile, rather than re-implementing disk cleanup |
| `app/Livewire/Admin/UsersTable.php` / `resources/views/livewire/admin/users-table.blade.php` | Lists users with an "Edit" row action (Slice A); no lifecycle actions yet | Gains three Livewire methods (`deactivate`, `reactivate`, `delete`) and their `wire:confirm` row buttons — see the "Row actions share one file" risk |
| `app/Enums/UserStatus.php` | `Active`, `Inactive`, `Deleted` already exist; `canLogIn()` already gates on `Active` | No enum change. Deactivate/reactivate/delete just move a user between these three values |
| `app/Http/Middleware/EnsureAccountRemainsActive.php` (AD-015) | Already re-checks `status` on every request and force-logs-out a non-active session | **This is why `DeactivateUser` needs no session bookkeeping of its own** (AD-015's own stated consequence for Slice D) — setting `status` is sufficient |
| `app/Support/Tenancy/TrainerContext.php`, `EnsureTrainerContext` | Resolves the active tenant per request from `$request->user()->role`; `Role::SuperAdmin => null` with a comment *"the read-only inspect tenant selection arrives in Slice D"* | **Explicitly out of this slice's FR list** — no FR asks for a Super Admin inspect-tenant screen, only impersonation. Left as `null`, noted as a gap below, not built |
| `app/Livewire/Context/ProfileSwitcher.php` | Already resolves "which trainable profile is the acting user managing" via `session('player_profile_id')`, re-validated against `trainableProfiles()` | Reused directly by `Availability\Grid` to resolve the subject for a Player/Parent — no new session key needed |
| `app/Livewire/Context/TrainerSwitcher.php`, `TrainerContext` | Already resolves "which organisation is active" per request | Reused directly by `Availability\Grid` to resolve which trainer an override applies to |
| `config/media.php` | `profile_photos` config block (disk, mime types, size, TTL) | Gains a sibling `trainer_logos` block; no change to the existing block |
| `config/filesystems.php` | `public` disk already defined (`storage/app/public`, `visibility: public`) | Trainer logos are stored here, not on the private `local` disk profile/child photos use — see Decision below |
| `resources/views/components/layouts/app.blade.php` | Shared shell: header, both switchers, nav, `session('status')` banner | Gains the impersonation banner (top, before the header) and a `--brand-primary` CSS custom property on `<body>` — see the "one shared layout file" risk |
| `routes/console.php` | One scheduled job (`ExpirePurchaseApprovalsJob`, Slice C) | Gains `CloseStaleImpersonationLogsJob` on the same cadence |
| `bootstrap/app.php` | `web` group carries `EnsureAccountRemainsActive`, `EnsureTrainerContext` | Gains `EnforceImpersonationTimeout`, appended between the two (skips a wasted tenant resolution on a request whose impersonation is about to be torn down) |
| `app/Actions/Fortify/UpdateUserPassword.php` | No authorization check today — reachable by any authenticated user for their own password via Fortify's `/user/password` | Gains one line: `Gate::authorize('user.change-credentials', $user)` — the chokepoint the impersonation write-guardrail hangs off |
| `app/Actions/Fortify/UpdateUserProfileInformation.php` | Docblock: *"email is read-only and needs its own flow"* — **no email-change surface exists yet** | Confirms the guardrail's email half is a documented convention for whoever builds that flow later, not code to write now |
| Policy auto-discovery | No `AuthServiceProvider`/`Gate::policy()` registrations exist; `App\Policies\{Model}Policy` resolves by convention | `AvailabilityPolicy` and `CoachAvailabilityOverridePolicy` need no registration step |
| `app/Enums/*` | `CoachStatus`, `ApprovalStatus`, `PaymentType`, `Role`, `ShareLinkType`, `TrainerPlayerStatus`, `UserStatus` — every domain concept already gets a backed enum with `label()` | `DayOfWeek` follows the same shape |

## Already Enforced — Do Not Rebuild

| Requirement | Where it already lives | What Slice D actually adds |
|---|---|---|
| BR-016: Super Admin cannot impersonate another Super Admin; target must be active | `UserPolicy::impersonate()` (Slice A) | One more condition (no impersonation already active) — see Decisions |
| Super Admin's Gate bypass is suspended while impersonating | `AppServiceProvider::registerGates()`'s second `Gate::before` + `NOT_BYPASSABLE` (Slice A) | Nothing — reused as the reason the acting identity's *own* permissions (the target's) govern every check during impersonation |
| Dual-identity attribution on every audited write | `AuditLogger::log()` (Slice A) already reads `session('impersonator_id')` | Nothing — `StartImpersonation` only has to *populate* that session key at the right moment |
| Deactivation blocks login and ends any live session without extra bookkeeping | `EnsureAccountRemainsActive` + `EnsureAccountIsActive` (Slice A, AD-015) | Nothing — `DeactivateUser` is a one-line status flip |
| `on_behalf_of_user_id` column | `audit_logs` migration (Slice A) | Nothing — no migration in this slice touches `audit_logs` |
| `logo_path` / `primary_color` columns and their mass-assignability | `trainer_profiles` migration + `TrainerProfile::#[Fillable]` (Slice A) | Nothing — no migration for branding; `UpdateTrainerBranding` uses ordinary `update()`, not `forceFill` |
| `TrainerProfilePolicy::updateBranding()` | Slice A | Nothing — reused as the only authorization FR-019 needs |
| Profile-photo disk write, sniff-then-decode validation, old-file cleanup | `StoreProfilePhoto` (Slice A/C) | Reused by `AnonymizeUser` for both the target and any anonymized child profile — no new disk-handling code |
| Mass-assignment guarding of privilege/ownership columns (AD-016) | `#[Fillable]` allow-lists + `forceFill` throughout Slices A–C | Same discipline applied to `Availability` (`trainer_profile_id`, `available_for_*`), `CoachAvailabilityOverride` (everything but `reason`), `ImpersonationLog` and `UserDeletionLog` (no allow-list at all, mirroring `AuditLog`) |
| Fail-closed tenancy, the two escape hatches | `TenantScope`, `BelongsToTenant`, `TrainerContext` (Slice B) | `CoachAvailabilityOverride` uses `BelongsToTenant` (it is tenant-owned per AD-001); `Availability` deliberately does **not** (Decision 3) |
| The two switchers (`player_profile_id` / trainer context in session) | `ProfileSwitcher`, `TrainerSwitcher`, `EnsureTrainerContext` (Slice B) | `Availability\Grid` reads both instead of inventing a third session key |

## Gaps And Decisions

| # | Item | Decision | Reversal cost |
|---|---|---|---|
| 1 | **Impersonation "no session already active" gate condition** — Decision 6 lists it as one of four gate conditions, but `UserPolicy::impersonate()` today only checks the *target* | Add a fourth condition to `impersonate()` itself: `! ($request->hasSession() && $request->session()->has('impersonator_id'))`, so all four conditions live in the one Policy method the Requirements table already names, not split between a Policy and a controller `if` | Remove the one condition |
| 2 | **The impersonation write guardrail's ability names** — Decision 6's guardrail covers "password or email" as one idea, but `update` is a heavily overloaded ability name across `User`, `CoachProfile`, `PlayerProfile`, `ShareLink`, `TrainerProfile` policies. A blanket `Gate::before` deny on `update` would also block a trainer editing their own bio while impersonating, which contradicts "permissions match the target exactly" for everything the guardrail does *not* name | Introduce one new, narrowly-scoped ability string, `user.change-credentials`, defined via `Gate::define()` (always `true` outside impersonation) and checked explicitly inside `UpdateUserPassword::update()` — the one live credential-change path today. Account deletion is scoped by *subject type*, not ability name alone (see `ImpersonationGuardrail::denies()` below), so deleting a `ShareLink` or `TrainerPlayer` (both also use ability `delete`) is unaffected | Delete the one `Gate::define()` line and the one `Gate::authorize()` call; both are additive |
| 3 | **Email is not part of the guardrail's live surface** — Decision 6 says "password or email," but no email-change flow exists anywhere in the codebase (`UpdateUserProfileInformation`'s own docblock: "email is read-only and needs its own flow") | Reserve `user.change-credentials` for email too; document it here as the ability whoever builds FR-016's future email-change flow must call. Nothing to build now — there is no call site to guard | None — pure documentation |
| 4 | **`event_id` on `coach_availability_overrides` has nothing to constrain against** — Epic-02's `events` table does not exist | Store it as a plain nullable `unsignedBigInteger`, no foreign key. Epic-02 adds the constraint in its own migration once the table exists. This is the explicit, named seam for FR-015's "partially blocked" half | Add the FK in a later migration; the column and its values are untouched |
| 5 | **FR-014's trainer-side CRM filter has no screen to live in** — "availability indicator in event creation and CRM" is Epic-02/Epic-03 territory | Ship the query as a method on `AvailabilityResolver` (`rosterAvailableAt()`), feature-tested directly against a seeded roster, with no Livewire component calling it. Symmetrical with FR-015's own deferral — record it the same way, not as a silent omission | Add the CRM screen later; the query and its indexes do not change |
| 6 | **Anonymizing a guarded child profile when the deleted user is one of *two* guardians** — brainstorming's Decision 7 says "recommend anonymizing owned PlayerProfile rows," written before AD-019 turned ownership into a many-to-many `player_guardians` relation, and does not address the two-parent case | Anonymize a guarded child's profile only when the deleted user is that child's **sole** active guardian (`$child->guardians()->count() === 1`). Anonymizing a child two people still legitimately guard would destroy data that does not belong to the deleted account. Recorded here as a refinement of Decision 7, not a silent reinterpretation | Widen the condition later if the client wants every guardianship severed to trigger anonymization regardless of co-guardians |
| 7 | **`Auth::login($target)` fires the framework's own `Login` event** | `AppServiceProvider::recordSuccessfulLogins()` (Slice A) reacts to it and bumps `users.last_login_at` — so every impersonation start also updates the *target's* last-login timestamp, and `AuditAuthenticationEvents::auditLogin` writes a second, ordinary `auth.login` audit row (correctly dual-attributed, since `impersonator_id` is already in the session by the time the event fires) alongside the dedicated `impersonation.started` entry. Left as-is: NFR-011 only requires impersonation to be logged, which it now is twice over, and suppressing a built-in framework event for one specific caller is more code than the ambiguity it removes | Add a session flag `recordSuccessfulLogins()` checks and skips, if a Super Admin later reports this as confusing on the user's activity report |
| 8 | **SVG logo uploads (FR-019 permits them; brainstorming flags them as an XSS vector)** | Restrict Slice D's upload validation to PNG/JPEG (reject SVG), per brainstorming's own recommended answer. **This is a real conflict between the requirement text and the design's recorded decision** — flagged here rather than silently resolved, and the FR-019 acceptance criteria should be corrected by the client if PNG/JPEG-only is not acceptable | Add `image/svg+xml` to the accepted MIME list and add server-side sanitization before storage; the validation call site does not otherwise change |
| 9 | **Trainer/coach profile data is not addressed by FR-018's anonymization mapping** — the requirement only describes scrubbing a *Player/Parent's* PII | `AnonymizeUser` scrubs the `User` row and owned/guarded `PlayerProfile` rows uniformly regardless of the target's role, but never touches `TrainerProfile.business_name` or `CoachProfile.bio`/`credentials` — doing so would erase a live organisation's identity or a coach's public listing that other people still depend on. Flagged for the client: deleting a Trainer or Coach account today anonymizes the *person*, not the *business* | If the client wants the business scrubbed too, that is a second, deliberately separate action — this one stays scoped to identity PII |
| 10 | **GDPR deletion-log retention (brainstorming's proposed 6-year purge)** | Add the `deleted_at` index and the `GDPR_DELETION_LOG_RETENTION_YEARS` config value now; **do not** build the purge job in this slice. No FR asks for it, and shipping an unrequested job that deletes compliance evidence on an unconfirmed horizon is a bigger risk than deferring it | Add `PurgeExpiredUserDeletionLogsJob` later; the config key and index already exist for it |
| 11 | **The Super Admin "inspect tenant" screen** that `EnsureTrainerContext`'s own comment says "arrives in Slice D" | Not built — no FR in this slice's scope (FR-012, FR-017, FR-018) describes it; it is a pre-existing Slice A comment that overstated this slice's boundary. Flagged, not silently dropped | Build it as its own small slice whenever a Super Admin needs to browse one organisation's tenant-scoped screens directly |
| 12 | **Trainer logos live on the `public` disk**, unlike profile/child photos (private `local` disk + signed route, AD-020) | Branding is not personal data — it is business identity meant to render for every authenticated member of the organisation on every page load, which a signed-URL-per-render pattern is the wrong shape for. Stored under `storage/app/public/branding/{trainer_profile_id}/`, requires `php artisan storage:link` in deployment (noted in Risks) | Move to the private disk behind a controller later if the client wants logos hidden from anyone outside the organisation |
| 13 | **Availability time-of-day columns are plain `TIME` columns with no Eloquent cast** | `start_time`/`end_time` stay as raw `H:i:s` strings on the model; string comparison (`'09:00:00' <= '17:00:00'`) is lexicographically correct for zero-padded `HH:MM:SS` and avoids a Carbon-on-a-dateless-TIME-column cast that buys nothing here | Add a cast later if a consumer needs `Carbon` arithmetic on a range |

## Proposed Design

### Schema

Four additive migrations; none edits a Slice A/B/C file.

- **`create_availabilities_table`** — `morphs('available_for')` (`available_for_type`,
  `available_for_id`), `trainer_profile_id` unsigned big integer **nullable**, `foreign` to
  `trainer_profiles`, `nullOnDelete()`; `day_of_week` unsigned tinyint (0=Sunday…6=Saturday, Carbon
  convention); `start_time` time nullable; `end_time` time nullable; `is_available` boolean default
  `true`; timestamps. A "Not Available" day is one row with `is_available = false` and null times.
  Indexes exactly as Decision 3 names them: `(available_for_type, available_for_id,
  trainer_profile_id)` and `(trainer_profile_id, day_of_week, start_time)`. **No global scope** —
  `Availability` does not use `BelongsToTenant` (Decision 3; AD-001's third data class).
- **`create_coach_availability_overrides_table`** — `coach_profile_id` constrained,
  cascadeOnDelete; `trainer_profile_id` constrained, cascadeOnDelete (tenant-owned, AD-001);
  `event_id` unsigned big integer, **nullable, unconstrained** (Gap 4 — the Epic-02 seam);
  `reason` text; timestamps. Index `(coach_profile_id, created_at)` for a coach's own override
  history; index on `trainer_profile_id` alone for the tenant scope. "The overriding trainer" is
  `trainer_profile_id → trainerProfile → user` (a `TrainerProfile` has exactly one owning user), so
  no separate actor column is needed.
- **`create_impersonation_logs_table`** — `admin_user_id`, `target_user_id` both `nullable`,
  `constrained('users')`, `nullOnDelete()` (mirrors `audit_logs`'s own choice; moot in practice
  since GDPR erasure here never hard-deletes a row); `started_at` timestamp; `ended_at` timestamp
  nullable; `duration_seconds` unsigned integer nullable; `ip_address` string(45) nullable;
  timestamps. Index `(target_user_id, started_at)` for the history report; index `(ended_at,
  started_at)` for `CloseStaleImpersonationLogsJob`'s sweep (`WHERE ended_at IS NULL AND
  started_at < ?`). **Identity table (AD-001) — no `BelongsToTenant`.**
- **`create_user_deletion_logs_table`** — `original_user_id` constrained('users'), `nullOnDelete()`
  (nullable — same reasoning as above); `email_hash` string(64); `deleted_by_user_id` nullable,
  constrained('users'), `nullOnDelete()`; `reason` text nullable; `deleted_at` timestamp;
  timestamps. Index on `email_hash` (the "was this address ever erased" lookup); index on
  `deleted_at` (unused until Gap 10's deferred purge job, cheap to add now). **No `SoftDeletes`
  trait** despite the `deleted_at`-shaped column name — see the model note below, this is the trap.

### Enums (`app/Enums/`)

- **`DayOfWeek`**: int-backed, `Sunday = 0` … `Saturday = 6` (Carbon's own convention, so
  `now()->dayOfWeek` maps directly with no translation table). `label()` returns the full name;
  `shortLabel()` returns `"Mon"`/`"Tue"`/… for the "Best Times: Mon 5-8pm" summary copy FR-014
  names — built now even though the CRM card that would render it is Gap 5's deferred screen.

### Support (`app/Support/Authorization/`)

- **`ImpersonationGuardrail`** — mirrors `ChildAbilities`'s exact shape:
  ```php
  final class ImpersonationGuardrail
  {
      public const DENIED = [
          'user.change-credentials',
          'payment-method.create',
          'payment-method.delete',
          'tokens.purchase',
          'purchase.complete',
      ];

      public static function denies(string $ability, mixed $subject = null): bool
      {
          if (in_array($ability, self::DENIED, true)) {
              return true;
          }

          // `delete` is shared with ShareLinkPolicy/TrainerPlayerPolicy; scoping by subject type
          // keeps those unaffected while impersonating — only deleting the *account itself* is
          // denied, matching Decision 6's "deleting the account" (not "deleting anything").
          return $ability === 'delete' && $subject instanceof User;
      }
  }
  ```
  The four payment/token entries have no live caller yet (same as `ChildAbilities`'s own
  unbuilt entries) — pre-declared so Epic-05 inherits the guardrail automatically.

### Models

- **`Availability`** — `#[Fillable(['day_of_week', 'start_time', 'end_time', 'is_available'])]`;
  `trainer_profile_id` and `available_for_*` are **not** fillable (AD-016 — they decide whose data
  this is). `morphTo()` as `availableFor()`; `belongsTo(TrainerProfile::class)`. Casts:
  `day_of_week` → `DayOfWeek::class`, `is_available` → `boolean`. No trait beyond `HasFactory` — no
  `BelongsToTenant` (Decision 3).
- **`CoachAvailabilityOverride`** — `use BelongsToTenant, HasFactory;` `#[Fillable(['reason'])]`
  only. `belongsTo(CoachProfile::class)`. `event_id` stays a plain nullable integer attribute, not a
  relation, until Epic-02's `Event` model exists.
- **`ImpersonationLog`** — no `#[Fillable]` at all (mirrors `AuditLog` — written only through
  `StartImpersonation`/`StopImpersonation`/`CloseStaleImpersonationLogsJob`, all via `forceFill`).
  `belongsTo(User::class, 'admin_user_id')`, `belongsTo(User::class, 'target_user_id')`. Casts:
  `started_at`/`ended_at` → `datetime`.
- **`UserDeletionLog`** — no `#[Fillable]`. `belongsTo(User::class, 'original_user_id')`,
  `belongsTo(User::class, 'deleted_by_user_id')`. Cast `deleted_at` → `datetime`. **Deliberately
  does not use `SoftDeletes`** — the column name collides with Eloquent's own soft-delete
  convention by coincidence (it records when the *original user* was erased, not when this log row
  was), and mixing the trait in would silently hide every row from every query. A one-line comment
  on the class states this explicitly, the same way Decision 6 names the `Auth::logout()` trap.
  `public static function hashEmail(string $email): string` — `hash_hmac('sha256',
  mb_strtolower(trim($email)), config('gdpr.email_hash_salt'))`, throwing if the salt is
  unconfigured (fail closed rather than hash with an empty key). Lower-cased and trimmed so the
  same address hashes identically regardless of how it was typed, which is what makes the hash
  "comparable across records" for a re-registration check.
- **`PlayerProfile`** — gains `availabilities(): MorphMany<Availability>` (identity-side
  convenience; not used by the resolver itself, which queries `Availability` directly).
- **`CoachProfile`** — gains the same `availabilities(): MorphMany<Availability>` and
  `overrides(): HasMany<CoachAvailabilityOverride>`.
- **`User`** — no change.

### Configuration

- **`config/gdpr.php`** (new): `email_hash_salt` (`env('GDPR_EMAIL_HASH_SALT')`, no default — must
  be set per environment, never hardcoded or committed), `deletion_log_retention_years`
  (`env('GDPR_DELETION_LOG_RETENTION_YEARS', 6)` — unused number until Gap 10's job exists, present
  so the column and the config agree from day one).
- **`config/media.php`** gains a `trainer_logos` sibling to `profile_photos`: `disk` (`env(...,
  'public')`), `directory` (`'branding'`), `max_kilobytes` (`env(..., 2048)` — FR-019's 2 MB),
  `mime_types` (`['image/jpeg', 'image/png']` — **not** `image/svg+xml`, Gap 8), `max_pixels`
  (`env(..., 400)` — FR-019 recommends 200×200; resized to fit within this, 2× for retina,
  preserving aspect ratio).
- **`.env.example`** gains `GDPR_EMAIL_HASH_SALT=`, `GDPR_DELETION_LOG_RETENTION_YEARS=6`,
  `TRAINER_LOGO_DISK=public`, `TRAINER_LOGO_MAX_KILOBYTES=2048`.

### Services (`app/Services/Availability/`)

- **`AvailabilityResolver`**:
  - `resolve(Model $subject, ?int $trainerProfileId): Collection<Availability>` — if
    `$trainerProfileId` is given and an override set exists for it, returns that set **wholly**
    (Decision 3 — no merge); otherwise returns the default (`trainer_profile_id IS NULL`) set.
  - `isUsingDefault(Model $subject, ?int $trainerProfileId): bool` — for the Grid's "Using my
    default times" / "Custom for Trainer B" label and its Reset control.
  - `rosterAvailableAt(TrainerProfile $trainer, DayOfWeek $day, string $start, string $end):
    Builder<PlayerProfile>` — the FR-014 CRM filter's query (Gap 5), joined from the already-scoped
    `trainer_players` (never `PlayerProfile::query()` directly, per AD-001/AD-009), using two
    correlated `EXISTS` subqueries against `availabilities`: one for "this player has an override
    for this trainer that covers the window," one for "this player has *no* override for this
    trainer, and their default covers the window" — because an override wholly replaces the
    default, a player with both must be judged by the override alone, not by either matching. Each
    `EXISTS` is an index lookup against `(available_for_type, available_for_id,
    trainer_profile_id)` plus the day/time predicate against `(trainer_profile_id, day_of_week,
    start_time)` — the two indexes Decision 3 names exist for exactly this query. A single
    trainer's own roster (the only scope this query ever runs against — tenancy makes a
    cross-organisation scan impossible by construction) is orders of magnitude below NFR-002's
    10,000-row directory scale, so the existing indexes are sufficient without further work; this
    is stated explicitly because NFR-002 is otherwise a Users-directory number (AD-012), not an
    availability number, and the plan should not leave that conflation implicit.
- **`CoachConflictChecker`**:
  - `hasConflict(CoachProfile $coach, DayOfWeek $day, string $startTime, string $endTime): bool` —
    resolves the coach's own set via `AvailabilityResolver::resolve($coach,
    $coach->trainer_profile_id)` (always non-null for a coach), returns `false` only if some
    `is_available = true` range fully contains `[$startTime, $endTime]`; `true` (a conflict)
    otherwise. No caller exists in this slice — Epic-02's event-assignment flow is the intended
    caller, and this is the stated seam (Gap 4 / the objective's FR-015 boundary).

### Actions

- **`Availability/SaveAvailability`** — `handle(PlayerProfile|CoachProfile $subject, ?int
  $trainerProfileId, array $ranges): void`. One transaction: delete every existing row for
  `($subject, $trainerProfileId)` (a `where('trainer_profile_id', $trainerProfileId)` call
  correctly compiles to `IS NULL` when the value is `null` — Laravel's query builder special-cases
  it), then bulk-insert `$ranges` via the `availableFor()` relation with `forceFill`. Passing an
  empty `$ranges` array with a non-null `$trainerProfileId` is exactly "Reset to default" — it
  deletes the override rows and the resolver falls back to the default set on the very next read.
  Audits `availability.saved` with `{trainer_profile_id, day_count}`.
- **`Availability/OverrideCoachAvailability`** — `handle(CoachProfile $coach, ?int $eventId, string
  $reason): CoachAvailabilityOverride`. `forceFill`s all four columns (`coach_profile_id` from the
  relation, `trainer_profile_id` from `$coach->trainer_profile_id`, `event_id`, `reason`), saves,
  audits `coach-availability.overridden`. No caller in this slice (FR-015's stated boundary) —
  exercised only by its own unit test and `CoachAvailabilityOverridePolicy`'s own test until
  Epic-02 wires an event-assignment flow to it.
- **`Admin/StartImpersonation`** — `handle(User $admin, User $target): void`. First line:
  `Gate::authorize('impersonate', $target)` (the action owns the guard, not the controller —
  matches the Requirements table's own phrasing, "guard the Super Admin rule," as part of the
  action). Then, **in this exact order** (Decision 6's trap, restated so it is not silently
  reordered later): create the `ImpersonationLog` row (`admin_user_id`, `target_user_id`,
  `started_at = now()`, `ip_address`); write `session(['impersonator_id' => $admin->id,
  'impersonation_log_id' => $log->id, 'impersonation_started_at' => now()->toISOString()])`; only
  **then** `Auth::login($target)` — never `Auth::logout()` first, because logout flushes the
  session and destroys the very keys just written. `Auth::login()` regenerates the session id
  while preserving session data, which is the behaviour this order depends on. Audits
  `impersonation.started` against `$target` — correctly dual-attributed, since `impersonator_id` is
  already in the session by the time this line runs.
- **`Admin/StopImpersonation`** — `handle(Request $request): void`, the single chokepoint called by
  both `ImpersonationController::stop()` and `EnforceImpersonationTimeout` on timeout. Reads
  `impersonator_id`/`impersonation_log_id`/`impersonation_started_at` from the session **before**
  touching anything. Audits `impersonation.stopped` against the admin **first**, while the session
  still holds `impersonator_id` (so the audit row is still dual-attributed) and while `auth()->id()`
  is still the target. Then: closes the `ImpersonationLog` (`ended_at = now()`, `duration_seconds`
  computed from `impersonation_started_at`); clears the three session keys; re-authenticates the
  admin via `Auth::login($admin)` — a fresh `User::findOrFail($adminId)` lookup, not a cached
  reference, so a mid-impersonation change to the admin's own account is respected. If the admin's
  account is no longer active (`! $admin->status->canLogIn()`), fails closed: logs the current
  (target) session out entirely and lets the caller redirect to `/login`, per Decision 6's
  explicit consequence — "if the admin's own account is deactivated mid-impersonation, stopping
  fails closed to the login screen."
- **`Admin/DeactivateUser`** — `handle(User $target): void`. Refuses if `$target->status ===
  UserStatus::Deleted` (irreversible, BR-018). `forceFill(['status' => UserStatus::Inactive])`,
  audits `user.deactivated`. No session bookkeeping (AD-015's own stated consequence).
- **`Admin/ReactivateUser`** — `handle(User $target): void`. Same guard against `Deleted`. `forceFill(['status' => UserStatus::Active])`, audits `user.reactivated`.
- **`Admin/AnonymizeUser`** — `handle(User $target, User $actor, ?string $reason): void`. Refuses
  if already `Deleted`. One transaction:
  1. Capture `$originalEmail = $target->email` **before anything is overwritten** — the trap: both
     the deletion-log hash and the `password_reset_tokens` cleanup below need the address exactly
     as it was, and a naive read-after-scrub would hash `deleted_{id}@deleted.invalid` instead.
  2. Write the `UserDeletionLog` row first (`original_user_id`, `email_hash =
     UserDeletionLog::hashEmail($originalEmail)`, `deleted_by_user_id = $actor->id`, `reason`,
     `deleted_at = now()`) — **before** the scrub, so a mid-failure never loses the record
     (Decision 7).
  3. `forceFill` the `User` row: `first_name = 'Deleted'`, `last_name = 'User'`, `email =
     "deleted_{$target->id}@deleted.invalid"` (`.invalid`, RFC 2606-reserved and guaranteed never
     to resolve — an improvement on the spec's `example.com`, which is reserved but live), `password
     = Hash::make(Str::random(40))` (never null — fails closed), `phone = null`, `remember_token =
     null`, `status = UserStatus::Deleted`. Save.
  4. `app(StoreProfilePhoto::class)->remove($target)` — reused, not reimplemented.
  5. `DB::table('sessions')->where('user_id', $target->id)->delete()`;
     `DB::table('password_reset_tokens')->where('email', $originalEmail)->delete()`.
  6. Anonymize owned/guarded `PlayerProfile` rows (Gap 6): the target's own self profile if any
     (`$target->playerProfile`), plus every child it guards where it is the **sole active
     guardian** (`$child->guardians()->count() === 1`). For each: `forceFill(['name' => 'Deleted
     User', 'birth_date' => null, 'school' => null, 'jersey_number' => null, 'emergency_contact' =>
     null])`, save, then `app(StoreProfilePhoto::class)->remove($profile, withThumbnail: false)`.
  Audits `user.anonymized` against `$target` with `{reason}` after the transaction commits.
- **`Trainer/UpdateTrainerBranding`** — `handle(TrainerProfile $trainer, ?TemporaryUploadedFile
  $logo, string $primaryColor): void`. Validates `$primaryColor` against `/^#[0-9A-Fa-f]{6}$/`
  before this action is ever reached (Livewire-side rule); if `$logo` given, sniffs MIME against
  `config('media.trainer_logos.mime_types')` (PNG/JPEG only, Gap 8), decodes via
  `ImageManager::decodeBinary()` (same two-gate discipline as `StoreProfilePhoto` — sniff, then
  decode-before-store, deleting a partial write on failure), resizes to fit within
  `config('media.trainer_logos.max_pixels')` preserving aspect ratio, stores under
  `config('media.trainer_logos.directory')/{trainer_profile_id}/{uuid}.{ext}` on
  `config('media.trainer_logos.disk')`, deletes the previous logo file, then
  `$trainer->update(['logo_path' => $path, 'primary_color' => $primaryColor])` — an ordinary
  `update()`, not `forceFill`, since both columns are already fillable and neither is a
  privilege/ownership column. A `reset()` counterpart sets `logo_path = null` and `primary_color =
  config('branding.default_primary_color')` (new tiny config value, e.g. `'#0EA5E9'`). Audits
  `trainer-branding.updated`.

### HTTP surface

| Route | Handler | Middleware | Notes |
|---|---|---|---|
| `GET /availability` | `Livewire\Availability\Grid` | `auth,verified,role:player` | Player/parent Best Times |
| `GET /coach/my-times` | `Livewire\Availability\Grid` | `auth,verified,role:coach` | Same component, branches on `auth()->user()->role` |
| `POST /admin/impersonate/{user}` | `Admin\ImpersonationController@start` | `auth,verified,role:super_admin` | Inside the existing `admin` route group |
| `POST /impersonate/stop` | `Admin\ImpersonationController@stop` | `auth,verified` **only** | **Deliberately outside `role:super_admin`** — the acting session is the target, who is never a Super Admin, once impersonation is live |
| `GET /admin/impersonation-history` | `Livewire\Admin\ImpersonationHistory` | `auth,verified,role:super_admin` | Paginated, per NFR-002's directory discipline (`simplePaginate`) |
| `GET /trainer/branding` | `Livewire\Trainer\Branding` | `auth,verified,role:trainer` | |

No new route for deactivate/reactivate/delete: they are Livewire methods on the existing
`UsersTable` component (`/admin/users`), matching `Overview::remove()`'s established pattern
(`wire:click` + `wire:confirm`) rather than inventing three redirect-only Controller endpoints for
actions a page that already exists can perform itself.

- **`UsersTable`** gains `deactivate(User $user)`, `reactivate(User $user)`, `delete(User $user)`
  — each `$this->authorize(...)` against the corresponding `UserPolicy` method, calls its Action,
  and lets Livewire's normal re-render show the new status/absence from the (unfiltered) list. The
  Blade view gains one `wire:confirm`-guarded button per action, visible per `@can`, plus a plain
  `<form>` POST to `admin.impersonate.start` (a real Controller route, not a Livewire method — see
  below) with its own `onsubmit` confirmation.
- **`Admin\ImpersonationController`** — `start(Request $request, User $user)`: delegates entirely
  to `StartImpersonation::handle()` (which owns the `Gate::authorize` call), redirects to
  `route('dashboard')` with a status flash naming the target. `stop(Request $request)`: no-ops
  (redirects to `dashboard`) if `impersonator_id` is absent from the session — a stray POST here is
  not an error, just nothing to stop; otherwise delegates to `StopImpersonation::handle()`,
  redirects to `route('admin.users.index')`.
- **`Livewire\Availability\Grid`** — `mount()` resolves `$subject` and `$trainerProfileId`:
  - Player/parent: `$subject` = the profile named by `session(ProfileSwitcher::SESSION_KEY)`,
    re-validated against `auth()->user()->trainableProfiles()` (the exact re-validation
    `ProfileSwitcher` itself already performs — reused, not re-derived); `$trainerProfileId` =
    `app(TrainerContext::class)->id()`.
  - Coach: `$subject` = `auth()->user()->coachProfile` (the active row); `$trainerProfileId` =
    `$subject->trainer_profile_id` (always non-null, no default/override toggle rendered at all).
  `$this->authorize('update', $subject)` via `AvailabilityPolicy`. Public property `array $ranges`
  (`day_of_week`, `start_time`, `end_time`); `addRange()`/`removeRange($index)`; `save()` validates
  (`end_time > start_time`, `day_of_week` 0–6) then calls `SaveAvailability::handle()`;
  `resetToDefault()` (Player only, only when `$trainerProfileId !== null`) calls the same action
  with an empty range set. Renders `AvailabilityResolver::isUsingDefault()`'s label ("Using my
  default times" / "Custom for {trainer}").
- **`Livewire\Admin\ImpersonationHistory`** — `simplePaginate(25)` over `ImpersonationLog`
  (identity table, unscoped), newest first, columns: admin, target, started/ended, duration,
  ip. `$this->authorize('viewAny', ImpersonationLog::class)` — a trivial policy (Super Admin only).
- **`Livewire\Trainer\Branding`** — `mount()` loads the acting trainer's own profile;
  `$this->authorize('updateBranding', $trainer)`; `save()`/`reset()` call
  `UpdateTrainerBranding`. Live preview via a CSS custom property bound to the colour input
  (`x-bind:style` on a preview swatch), matching the Requirements' Frontend Tasks list.

### Middleware

- **`EnforceImpersonationTimeout`** — appended to the `web` group in `bootstrap/app.php`, between
  `EnsureAccountRemainsActive` and `EnsureTrainerContext` (skips a wasted tenant resolution on a
  request about to be torn down). No-ops if `impersonator_id` is absent. Otherwise compares
  `impersonation_started_at` to now; under 60 minutes, passes through untouched; at or past 60,
  calls the **same** `StopImpersonation::handle($request)` the manual-stop controller calls
  (single chokepoint, not a second implementation of "restore and close"), then redirects to
  `route('dashboard')` with a distinct status ("Impersonation session expired after 60 minutes.")
  instead of letting the original request continue.

### Policies

- **`AvailabilityPolicy::update(User $user, PlayerProfile|CoachProfile $subject): bool`** —
  `PlayerProfile` branch: `$user->id === $subject->user_id || $subject->isGuardedBy($user)` (the
  same `ownsOrIs` shape `PlayerProfilePolicy` uses; **not** excluded for a child login, unlike
  `manageTrainerAssociations` — `ChildAbilities::DENIED` does not name availability, so a child
  with its own login may set its own Best Times, matching FR-014's framing of this as the child's
  own preference data, not a trainer-association decision). `CoachProfile` branch: `$user->id ===
  $subject->user_id`.
- **`CoachAvailabilityOverridePolicy::create(User $user, CoachProfile $coach): bool`** —
  `$user->role === Role::Trainer && $user->trainerProfile?->id === $coach->trainer_profile_id`.
  Built now, called by nothing until Epic-02 wires an event-assignment flow to
  `OverrideCoachAvailability` — the same "seam built in full, unwired" treatment as
  `ApprovedPurchaseExecutor`.
- **`ImpersonationLogPolicy::viewAny(User $user): bool`** — `$user->isSuperAdmin()`.
- **`UserPolicy`** (edited, not new):
  ```php
  public function impersonate(User $user, User $subject): bool
  {
      return $user->isSuperAdmin()
          && ! $subject->isSuperAdmin()
          && $subject->status === UserStatus::Active
          && ! $this->impersonationAlreadyActive();
  }

  public function reactivate(User $user, User $subject): bool
  {
      return $user->isSuperAdmin() && ! $user->is($subject);
  }

  protected function impersonationAlreadyActive(): bool
  {
      $request = request();

      return $request->hasSession() && $request->session()->has('impersonator_id');
  }
  ```
  (Gap 1 — the fourth gate condition folded into the one method the Requirements table already
  names, rather than split into a controller `if`.)

### AppServiceProvider additions

```php
// registerGates(), alongside the existing Gate::define('trainer.associate', ...):
Gate::define('user.change-credentials', fn (User $user): bool => true);

// A new, separate Gate::before — distinct from the existing NOT_BYPASSABLE hook, which only
// suspends the Super Admin *bypass*. This one denies specific abilities outright while
// impersonating, regardless of what the target's own permissions would otherwise allow.
Gate::before(function (User $user, string $ability, array $arguments = []): ?bool {
    if (! $this->isImpersonating()) {
        return null;
    }

    $subject = $arguments[0] ?? null;

    return ImpersonationGuardrail::denies($ability, $subject) ? false : null;
});
```

`isImpersonating()` is the existing protected method — reused verbatim, not duplicated.

### Notifications

None. FR-012/FR-017/FR-018/FR-019 are all synchronous admin actions with in-page confirmation
copy; nothing here fits the `database`/`mail` notification shape Slice C introduced.

### Scheduler (`routes/console.php`)

```php
Schedule::job(new CloseStaleImpersonationLogsJob)->everyFifteenMinutes();
```

`CloseStaleImpersonationLogsJob::handle()`: `ImpersonationLog::whereNull('ended_at')
->where('started_at', '<', now()->subMinutes(60))->chunkById(200, ...)`, closing each with
`ended_at = started_at->addMinutes(60)` and `duration_seconds = 3600` — the timeout **ceiling**,
not `now()`, so a sweep that runs days after an abandoned tab does not report an absurd multi-day
duration. This is the AD-008 "scheduler is a required process" risk restated for a second job: a
tab abandoned mid-impersonation without the sweep running leaves the compliance report showing an
open-ended row forever.

## Implementation Steps

Steps within one track are strictly sequential (each depends on the previous). Tracks are
independent of each other — no shared files until the final integration step — so they can be
built in parallel by separate implementation agents.

### Track A — Availability (FR-014, FR-015's non-UI half) — sequential internally

1. **Schema and enum**: `create_availabilities_table`, `create_coach_availability_overrides_table`
   migrations; `DayOfWeek` enum. **Verify**: migrations run clean against MariaDB; a unit test
   asserts `DayOfWeek` cases and both label methods. **Independent** of every other track.
2. **Models and resolver**: `Availability`, `CoachAvailabilityOverride` models;
   `AvailabilityResolver` (all three methods, including `rosterAvailableAt()`); `AvailabilityPolicy`;
   `CoachAvailabilityOverridePolicy`. Depends on step 1. **Verify**: unit tests for
   `AvailabilityResolver::resolve()`/`isUsingDefault()` against seeded default-only, override-only,
   and both-present fixtures; a feature test seeds a trainer's roster (mixed default/override
   players) and asserts `rosterAvailableAt()` returns exactly the expected set for a given
   day/time, including the "override replaces, does not merge with, the default" case explicitly.
3. **Actions**: `SaveAvailability`, `OverrideCoachAvailability`, `CoachConflictChecker`. Depends on
   step 2. **Verify**: `SaveAvailability` — wholesale replace (old rows gone, new rows present),
   reset-to-default (empty `$ranges` deletes the override, resolver falls back); `CoachConflictChecker`
   — a conflict matrix (inside/outside/spanning/adjacent ranges) as a unit test, no HTTP involved;
   `OverrideCoachAvailability` — writes the row with a null `event_id`, audits correctly, and its
   own policy test asserts only the coach's own trainer may create one.
4. **UI**: `Livewire\Availability\Grid`, both routes, its Blade view. Depends on step 3.
   **Verify**: a player sets and re-loads their default Best Times; a parent switches to a specific
   child via the existing `ProfileSwitcher` and sets an override for the active trainer without
   touching the default (asserted by re-reading the default set unchanged); "Reset to default"
   removes the override; a coach's page shows no default/override toggle at all and writes directly
   against their own `trainer_profile_id`; a stranger and a non-guardian both get 403.

### Track B — Impersonation (FR-012) — sequential internally

5. **Policy, schema, guardrail**: `UserPolicy::impersonate()` edit + new `reactivate()`;
   `create_impersonation_logs_table`; `ImpersonationLog` model; `ImpersonationGuardrail`; the
   `user.change-credentials` `Gate::define()` and its one call inside `UpdateUserPassword::update()`;
   the new impersonation-guardrail `Gate::before` in `AppServiceProvider`. **Independent** of every
   other track. **Verify**: `ImpersonationGuardrailTest` iterates `DENIED` asserting each is refused
   while a session carries `impersonator_id` (set directly in the test, no full Start flow needed);
   a dedicated test proves the `delete`+`User`-subject branch by authenticating as a
   **Super-Admin-flagged** user with `impersonator_id` also set and asserting `Gate::denies('delete',
   $otherUser)` — a scenario BR-016 prevents in the real Start flow, used here specifically to
   isolate the guardrail from `UserPolicy::delete()`'s own (also-denying, for unrelated reasons)
   verdict, so the assertion would fail if the guardrail hook were ever removed; a feature test
   confirms `PUT /user/password` is refused while impersonating and succeeds identically to today
   once impersonation is stopped.
6. **Actions and controller**: `StartImpersonation`, `StopImpersonation`, `EnforceImpersonationTimeout`
   middleware (appended in `bootstrap/app.php`), `CloseStaleImpersonationLogsJob` + scheduler
   registration, `Admin\ImpersonationController`, both routes. Depends on step 5. **Verify**: start
   writes the three session keys and regenerates the session id without ever calling
   `Auth::logout()` (asserted by checking the pre-start session's other data, e.g. an unrelated
   flash value, survives); the target's every subsequent write is dual-attributed
   (`audit_logs.actor_user_id = target`, `on_behalf_of_user_id = admin`); attempting to start a
   second impersonation while one is active is refused; impersonating another Super Admin is
   refused (BR-016, already covered by the existing Slice A policy test, re-asserted here at the
   controller level); stop re-authenticates the admin by a fresh lookup, not a stale reference,
   and fails closed to `/login` if the admin's own account was deactivated mid-session; the
   timeout middleware force-stops and redirects past 60 minutes and passes through untouched at 59;
   `CloseStaleImpersonationLogsJob` closes an abandoned log with `ended_at = started_at + 60min`,
   not `now()`, and leaves a fresh log alone; the job is present in `schedule:list`.
7. **UI**: the impersonation banner Blade component, the `UsersTable` Blade view's new
   "Impersonate" form, `Livewire\Admin\ImpersonationHistory` + its route. Depends on step 6.
   **Verify**: the banner renders during impersonation and not otherwise, colour-coded, with a
   working "Exit Impersonation" button posting to `/impersonate/stop`; the history report lists
   started/ended/duration for a completed session and shows an open row (no `ended_at`) for one
   still active; a non-Super-Admin gets 403 on the history route.

### Track C — Admin lifecycle: deactivate, reactivate, delete (FR-017, FR-018) — sequential internally, one combined UI step

8. **Actions and GDPR schema**: `DeactivateUser`, `ReactivateUser`; `create_user_deletion_logs_table`
   migration; `UserDeletionLog` model (with the `SoftDeletes`-collision comment); `config/gdpr.php`;
   `.env.example` entries; `AnonymizeUser`. **Independent** of Tracks A and B. **Verify**:
   deactivate blocks the next login attempt and ends any live session on its very next request
   (reusing `EnsureAccountRemainsActive`'s existing test pattern, not a new mechanism); reactivate
   restores login; both refuse a `Deleted` target; `AnonymizeUser` — the full field-by-field mapping
   against an explicit expectation table (mirroring Slice C's anonymization-style unit test),
   `email_hash` computed from the pre-scrub address and comparable across two log rows for the same
   original address (hash the same string twice, assert equality), the log row exists even if a
   later step in the transaction were to fail (asserted by checking write order, not by forcing a
   real failure), a solely-guarded child profile is anonymized while a co-guarded child's is not
   (Gap 6's regression test — the one most likely to be silently skipped), the target's own self
   profile (if any) is anonymized so a trainer's roster renders "Deleted User" per BR-018, sessions
   and password-reset tokens for the original email are cleared, reactivation of a `Deleted` account
   is refused.
9. **UI** (combined, not split — see the risk below): `UsersTable`'s three new Livewire methods
   (`deactivate`, `reactivate`, `delete`) and their `wire:confirm` row buttons, added together in
   one commit since all three touch the same component and Blade view. Depends on step 8.
   **Verify**: each button is visible only per its own `@can`; deactivating flips the visible status
   chip and swaps the button to "Reactivate"; deleting is refused with a 403-equivalent Livewire
   validation error for a non-Super-Admin; the confirmation copy matches FR-017/018's required
   wording (history preserved / irreversible).

### Track D — Trainer branding (FR-019) — no dependency on any other track

10. **Action and UI**: `config/media.php`'s `trainer_logos` block, `.env.example` entries,
    `UpdateTrainerBranding`, `Livewire\Trainer\Branding` + its route and Blade view. **Verify**: a
    logo upload is resized and stored, replacing the previous file; SVG is rejected (Gap 8) with a
    field error naming the accepted types, never a 500; an invalid hex colour is rejected; reset
    clears the logo and restores the default colour; a non-owner trainer gets 403.

### Final integration — sequential, after 4, 7, 9 and 10 all land

11. **Shared layout wiring**: `resources/views/components/layouts/app.blade.php` gains the
    impersonation banner include and a `--brand-primary` CSS custom property read from
    `app(TrainerContext::class)->get()?->primary_color` (falling back to the platform default when
    no tenant is resolved), plus nav links for `/trainer/branding` (trainer role) and
    `/admin/impersonation-history` (Super Admin). **This single file is the one genuine shared-file
    dependency across the whole slice** — Tracks B and D each add one self-contained line to it, so
    land this step after both are merged rather than attempting it inside either track, to avoid
    two agents editing the same file concurrently. **Verify**: `ScreenRenderTest`-style smoke test
    that the shared layout still renders for every role with no tenant resolved (Super Admin) and
    with one resolved (Trainer/Coach/Player), asserting the CSS variable is present in both cases
    with the correct value.
12. **Green suite, specs, memory**: full suite, Pint, PHPStan level 5, `schedule:list` (confirms
    both scheduled jobs). Append a `[TASK-001]` Slice D section to `specs/architect-architecture.md`
    (noting the `Services/Availability/` subdirectory choice as a deliberate divergence from the
    architecture doc's flat `Services/` listing, matching the `Services/Approval/` precedent
    Slice C already set) and update `specs/MANIFEST.md`. Capture through `/memory-bank`: the
    guarded-child-profile anonymization refinement (Gap 6) as a durable convention for whoever next
    touches GDPR deletion; the `Auth::login()`-fires-`Login`-event side effect (Gap 7) as a
    documented, accepted quirk rather than a bug someone rediscovers; the SVG-upload conflict
    (Gap 8) as an open item for the client, since it contradicts FR-019's literal acceptance text.

**Parallelization summary**: steps 1, 5, 8, 10 are the four independent starting points (no shared
files, no cross-track dependency) — they may run as four simultaneous agents. Within each track,
steps proceed strictly in the given order. Step 11 is the one convergence point and must wait for
the UI steps of every track that touches the shared layout (4 does not touch it; 7, 9's row
actions live in `UsersTable`, not the shared layout; only Tracks B's banner and D's CSS variable do)
— concretely, step 11 waits on steps 7 and 10. Step 12 waits on everything.

## Test Plan

**Feature** — the bulk:
- Availability: default set save/read, per-trainer override wholly replacing the default, reset to
  default, coach's fixed (no-toggle) grid, cross-role authorization refusals, the CRM roster query
  against a mixed-default/override roster.
- Impersonation: start (including the two refusals — already-a-Super-Admin target, already-active
  session), dual attribution on a write made while impersonating, manual stop, timeout force-stop,
  the abandoned-tab sweep, the write guardrail (password change, account deletion) both denied
  during impersonation and restored to normal once stopped, the history report.
- Admin lifecycle: deactivate ends the next request's session with no extra bookkeeping, reactivate,
  anonymization's full field mapping, the sole-vs-co-guardian child-profile branch, session/token
  cleanup, refused reactivation/deletion of an already-deleted account.
- Branding: logo upload/resize/replace, SVG rejection, colour validation, reset, non-owner refusal,
  the shared layout reflecting the active trainer's colour.

**Unit**:
- `DayOfWeek` cases/labels.
- `AvailabilityResolver::resolve()`/`isUsingDefault()` across the three fixture shapes.
- `CoachConflictChecker`'s conflict matrix.
- `ImpersonationGuardrail::denies()` — the plain array membership cases and the `delete`+`User`
  subject-scoped case, independent of any HTTP layer.
- `UserDeletionLog::hashEmail()` — same input hashes identically regardless of case/whitespace;
  different inputs never collide (a cheap sanity check, not a cryptographic proof).

**Policy**: `AvailabilityPolicy` per role (guardian, child login, coach, stranger);
`CoachAvailabilityOverridePolicy` (own trainer vs. another trainer's coach);
`UserPolicy::impersonate`/`reactivate` per the four gate conditions each, including the new "already
active" one; `TrainerProfilePolicy::updateBranding` re-asserted from the new UI's call site (already
covered once in Slice A — this just proves the new component actually calls it).

**Database**: both new `impersonation_logs` indexes exercised by the timeout-sweep and
history-report queries, not just present; the two `availabilities` indexes exercised by
`rosterAvailableAt()`'s `EXPLAIN`-visible index usage (asserted by query count/shape, not a raw
`EXPLAIN` parse, matching how `TenancyQueryBudgetTest` already pins query counts elsewhere in this
codebase).

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

```bash
ddev exec php artisan schedule:list
```

Confirms both `ExpirePurchaseApprovalsJob` (Slice C) and `CloseStaleImpersonationLogsJob` (this
slice) are registered — AD-008 treats the scheduler as a functional requirement, and this slice
adds its own instance of the same risk.

## Risks

| Risk | Mitigation |
|---|---|
| **The `Auth::login()`-before-`Auth::logout()` ordering is the single most security-critical line in this slice** — swapping it would flush the session and lose the just-written `impersonator_id`/`impersonation_log_id`, silently breaking attribution and the ability to ever restore the admin | Named explicitly in the design (Decision 6's own framing, restated at the action level); the step-6 verification asserts session survival directly rather than only asserting the end-to-end behaviour, so a regression here fails fast and close to the cause |
| **`Auth::login($target)` fires the framework's `Login` event**, updating the target's `last_login_at` and writing a second, ordinary `auth.login` audit row on every impersonation start | Recorded as Gap 7, deliberately accepted rather than silently suppressed; revisit if a Super Admin later finds the activity report confusing |
| **The impersonation write guardrail is easy to under-test** — a test that only proves "deleting is denied while impersonating" without isolating the guardrail from `UserPolicy::delete()`'s own (also-denying, for unrelated reasons) verdict would pass with or without the guardrail present, the exact "pins nothing" failure mode this codebase has hit before (`MEM-20260902-6bede1f2`) | Step 5's test explicitly constructs the one scenario where the Policy alone would allow the action, isolating the guardrail's own contribution |
| **`EnforceImpersonationTimeout` never fires if the scheduler is not the only safety net** — the middleware only catches a request that actually arrives; `CloseStaleImpersonationLogsJob` is what catches an abandoned tab, and it never fires if the scheduler is not running (the same AD-008 risk Slice C already carries for `ExpirePurchaseApprovalsJob`) | `schedule:list` added to verification; both jobs share the same conditional-close discipline, so a late run is safe, just delayed |
| **SVG rejection (Gap 8) contradicts FR-019's literal acceptance text** ("PNG/JPG/SVG") | Recorded explicitly as a requirement/design conflict rather than silently resolved; the client should confirm before this ships if SVG support is actually required |
| **Anonymizing a guarded child profile with two guardians would be a real data-loss bug** if the "sole guardian" condition were ever dropped or inverted | Gap 6's regression test asserts the co-guarded case is left untouched, not just that the sole-guardian case is scrubbed — the two assertions catch different mistakes |
| **`UserDeletionLog`'s `deleted_at` column name collides with Eloquent's `SoftDeletes` convention** — adding the trait later (e.g. by a future developer reaching for the familiar pattern) would silently hide every anonymization record from every query | Named explicitly in the model's own comment and in this plan's schema section; no code change prevents it, only the documentation does, which is the same limit `AuditLog`'s "no allow-list" comment already accepts |
| **`event_id` on `coach_availability_overrides` is an unconstrained column until Epic-02** | Recorded as Gap 4, the explicit seam; Epic-02's own migration is responsible for adding the foreign key once the `events` table exists |
| **The trainer logo's `public` disk requires `php artisan storage:link`** in every environment, unlike the private profile-photo disk which needs no symlink | Added to the deployment checklist alongside AD-008's queue-worker/scheduler requirement — an easy step to forget once, since nothing in this codebase has needed it before this slice |
| **`resources/views/components/layouts/app.blade.php` is the one shared file two tracks touch** (impersonation banner, branding CSS variable) | Sequenced as a dedicated final integration step (11) rather than inside either track, so no two agents edit it concurrently |
| **The CRM filter query (Gap 5) has no screen to exercise it end-to-end** until Epic-03 | Feature-tested directly against a seeded roster now, so Epic-03 wires a UI to a query already proven correct and performant rather than writing new query logic under a UI deadline |
