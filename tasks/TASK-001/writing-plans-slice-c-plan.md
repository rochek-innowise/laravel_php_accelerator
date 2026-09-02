---
description: Implementation plan for Epic-01 Slice C — child profiles, guardian-managed trainer associations, and the parent purchase-approval domain.
---

# Epic-01 Slice C — Implementation Plan

**Task**: TASK-001
**Scope**: Slice C only — FR-008 (child profiles), FR-009 (child-trainer associations), FR-010
(purchase approvals), FR-011 (child login constraints). Slice D (impersonation, availability,
deactivate/GDPR delete, branding) and FR-020 (camp-to-user, no acceptance criteria) are out of
scope and not planned here.

**Depends on**: Slices A and B, complete and green on `main` — 237 tests, Pint clean, PHPStan
level 5 clean.

## Goal

A parent adds a child profile — age 1–18, optionally with its own login — and chooses which of
the family's existing trainers the child joins, or declines and leaves it unassociated. The
`/family` screen lists every child with its trainers and lets a guardian add or remove an
association, history preserved. A child's USD purchase (and a token purchase unless the parent
has opted the child out of approval) creates a `PurchaseApproval` that a guardian approves or
denies from `/approvals`; an unanswered request auto-expires after 48 hours. A child login can
browse, RSVP, and update its own profile, but is refused everywhere FR-011 forbids it — including
a ShareLink, which now tells the child to ask a parent instead of failing silently. Epic-02/05 do
not exist yet, so there is no checkout UI: the approval domain is exercised end to end against
`NullPurchaseExecutor`, a test double standing in for Epic-05.

## Existing Context

| File | Current state | Impact in Slice C |
|---|---|---|
| `app/Models/PlayerProfile.php` | Identity, unscoped; `is_child`, `token_spend_requires_approval`, `guardians()`, `trainerAssociations()`, `trainers()`, `user()` all present | Gains `purchaseApprovals()`; no schema change needed for the fields FR-008 already stores |
| `database/migrations/…_create_player_profiles_table.php` | No `photo_path` column | New additive migration adds it (see Decision — Photo storage below); `owner_user_id` is already gone (AD-019, applied by the `player_guardians` migration) |
| `app/Models/TrainerPlayer.php` | Tenant-owned, `SoftDeletes`, `#[Fillable(['status'])]`, unique index includes `deleted_at` so a removed row can be re-created | FR-009 "remove" is exactly this soft delete; "re-add after remove" is a **new row**, not a resurrection — the unique index was built for this in Slice B |
| `app/Actions/Family/AssociatePlayersWithTrainer.php` | Re-derives the permitted set from `$actor->trainableProfiles()`, `firstOrCreate`-style idempotent associate, accepts a nullable `$via` ShareLink, own docblock: *"removal arrives with the family screen in Slice C"* | Reused **as-is** for FR-009's "add from existing trainers" — no new association-side code |
| `app/Actions/ShareLink/RedeemShareLink.php` `::forPlayer()` | Locks the link, associates, notifies after commit | Reused **as-is** for FR-009's "add via manual ShareLink entry" — the `/family` screen's manual-code field calls this exactly like `/join/{code}` does |
| `app/Models/User.php` | `trainableProfiles()` **memoizes** the self-profile + guarded-children set on the instance for the request | A newly created child is invisible to a stale cache — `CreateChildProfileAction` must invalidate it before calling `AssociatePlayersWithTrainer` for the chosen trainers, or the first association silently drops the child it just created |
| `app/Support/Authorization/ChildAbilities.php` | `DENIED` already covers `trainer.associate`, `purchase.complete`, `tokens.purchase`, `payment-method.*`, `parent-data.view`, `account.delete`, `trainer-association.change` | FR-011's deny half needs **no new entries** — Slice C's job is the allow half plus the ShareLink UX |
| `app/Livewire/Join/RedeemShareLink.php` `::join()` | `$this->authorize('trainer.associate')` throws for a child login, which Livewire renders as a bare 403 | Replaced with an explicit branch: friendly copy, a `ChildShareLinkBlocked` notification to the child's guardians, no exception, no association |
| `tests/Feature/Join/RedeemShareLinkTest.php::a_child_account_cannot_join_an_organisation` | Asserts `assertForbidden()` (raw 403) for exactly this path | **Must change** to assert the blocked-copy view and the queued notification instead of a 403 — flagged here so it is not mistaken for a regression |
| `app/Policies/PlayerProfilePolicy.php` | `create()` already requires `role === Player && ! is_child_account`; `update()` already allows the child's own login (`ownsOrIs`); `manageTrainerAssociations()` already requires guardianship **and** `! is_child_account`; `view()` carries a Slice-B TODO about trainer reachability | Reused as-is. The TODO is a **pre-existing Slice B gap** (a trainer reaching a player through an active `TrainerPlayer` row), not something FR-008–011 asks Slice C to close — noted in Risks, not fixed here |
| `app/Policies/TrainerPlayerPolicy.php` | `manage()` and `delete()` already require guardianship and `! is_child_account`, or trainer-of-record | Reused as-is for the "remove" authorization — zero new policy code for FR-009 |
| `app/Actions/Fortify/CreateNewUser.php` | Validates + creates a `User`, `forceFill`s `role`/`status`, does **not** create a self profile | Reused for the optional child-login sub-step (see Decision — Child login below), followed by `forceFill(['is_child_account' => true])` |
| `app/Jobs/DeactivateRosterAssociations.php` | The one existing example of AD-002's pattern: serializes an id, re-resolves, wraps in `runFor()` | Not the pattern for `ExpirePurchaseApprovalsJob` — `PurchaseApproval` is owner-scoped, not tenant-owned (AD-001), so the new job needs no `TrainerContext` at all |
| `app/Services/AuditLogger.php` | `log(action, subject, metadata)`, `forceFill`, records `on_behalf_of_user_id` | Reused verbatim for every new audited action |
| `routes/web.php`, `bootstrap/app.php` | `EnsureTrainerContext` and `EnsureAccountRemainsActive` are appended to the `web` group, so every request — including `/family` and `/approvals` — already carries a resolved (possibly null) tenant | No route-level `tenant` middleware needed on Slice C's routes: family and approval data is reached through identity relations (`trainableProfiles()`, `guardedPlayerProfiles()`), never through a tenant-scoped query |
| Policy auto-discovery | No `AuthServiceProvider`/`Gate::policy()` calls exist; Laravel resolves `App\Policies\{Model}Policy` by convention | `PurchaseApprovalPolicy` needs no registration step |
| `app/Notifications/*` | Three notifications exist, all `mail`-only, all `ShouldQueue` | Slice C introduces the first **`database`**-channel notifications (AD-011) and the `notifications` table migration, which does not exist yet |
| `routes/console.php` | Only the stock `inspire` command | Slice C registers the first real scheduled job — the actual first exercise of AD-008's "scheduler is a required process" for this project |

## Already Enforced — Do Not Rebuild

| Requirement | Where it already lives | What Slice C actually adds |
|---|---|---|
| A child login is refused `trainer.associate`, `purchase.complete`, `tokens.purchase`, `payment-method.*`, `account.delete`, `trainer-association.change`, `parent-data.view` (FR-011 deny half) | `ChildAbilities::DENIED` + the `Gate::before` hook in `AppServiceProvider` (Slice A) | Nothing — the array is complete for FR-011. Only the ShareLink UX around one of these denials changes (copy, not the denial) |
| Only a guardian (never the child) manages trainer associations, for any profile including their own | `PlayerProfilePolicy::manageTrainerAssociations()`, `TrainerPlayerPolicy::manage()`/`delete()` (Slice B) | Wiring these into the `/family` UI; no new policy logic |
| A child login may update its own profile's basic fields | `PlayerProfilePolicy::update()` via `ownsOrIs()` (Slice A) | Nothing — FR-011's "update basic profile" allowance already holds |
| Two guardians can both reach and manage one child; a guardian reaches several children | `player_guardians` pivot, `PlayerProfile::guardians()`/`isGuardedBy()`, `User::guardedPlayerProfiles()`/`trainableProfiles()` (AD-019) | Nothing — `CreateChildProfileAction` attaches through the same pivot, `GuardianshipTest` already covers the authorization consequences |
| Idempotent, re-derived trainer association from a submitted id list; a soft-deleted association can be re-created without a unique-index collision | `AssociatePlayersWithTrainer`, the `(trainer_profile_id, player_profile_id, deleted_at)` unique index (Slice B) | Reused directly for FR-009's two "add" paths; the "remove" path is the one genuinely new piece |
| Mass-assignment guarding of privilege/ownership columns (AD-016) | `#[Fillable]` allow-lists + `forceFill` throughout Slices A/B | Same discipline applied to `PurchaseApproval` (only `parent_note` is ever request-writable) and to the child-login `User` row |
| Fail-closed tenancy, the two escape hatches, identity-vs-tenant reads | `TenantScope`, `BelongsToTenant`, `TrainerContext` (Slice B) | Not touched — `PurchaseApproval` and the family screens are identity/owner reads by design (AD-001's third data class), so nothing here needs a tenant at all |

## Gaps And Decisions (recorded, not blocking)

Each is cheap to reverse; none is a schema decision that would be expensive to undo later.

| # | Item | Decision | Reversal cost |
|---|---|---|---|
| 1 | **Age 1–18 (FR-008)** maps onto a stored `birth_date`, not a stored age | Validate `birth_date` such that `Carbon::parse($value)->age` is between 1 and 18 inclusive, computed at submission time. No new column. | Change the validation rule |
| 2 | **Duplicate name+age warning (FR-008)** — global name search would leak family membership across accounts | Scope the check to the **acting guardian's own** guarded children only (`$actor->guardedPlayerProfiles()`), comparing normalized name equality and matching birth year. Presented as a dismissible confirmation, never a hard block. | Widen the query scope later if the client asks for something broader |
| 3 | **How a child gets its own login** — no FR describes this mechanism at all (not even in the Gap Analysis), yet FR-011 is unreachable without one | `CreateChildProfileAction` accepts an optional "give this child a login" toggle with email + password, reusing `CreateNewUser` for validation, then `forceFill(['is_child_account' => true])`. Flagged as an unlisted gap for the client, alongside G-01…G-12. | Hide the toggle; profile-only children remain fully supported either way |
| 4 | **Trainer-selection count (FR-008)** — "single-trainer parent" vs "multi-trainer parent" is ambiguous about whose trainers count | The union of trainers already associated with **any** of the guardian's trainable profiles (self + existing children) — matches the phrase "the parent's existing trainers" in FR-009's own acceptance | One query change if the client means "the parent's own self-profile trainers" only |
| 5 | **Photo storage for a child profile (FR-008)** — `player_profiles` has no photo column; `users.photo_path` only serves a login | Additive `photo_path` (nullable) on `player_profiles`. Reuses AD-020's validation order (mimetype sniff, then decode-before-store) served through a small extension to `ProfilePhotoController` accepting a `player` route param guarded by `PlayerProfilePolicy::view`. **Thumbnailing is skipped for this slice** — full-size only. | Add the thumbnail pass later; the column and controller branch do not change |
| 6 | **"Request more info" (FR-010)** — a third parent response with no state-machine transition defined anywhere (brainstorming Decision 4 ratifies only `pending → approved | denied`) | **Deferred.** Slice C ships Approve/Deny only. Adding a note-only, non-terminal interaction is additive and does not touch the state machine, so it costs nothing to defer. | Add a `parent_note`-only endpoint later; no migration needed, the column already exists |
| 7 | **Token-bypass bookkeeping (BR-014)** — "no approval row" (brainstorming Decision 4) vs. every transition needing to be idempotent and auditable | The bypass still writes a `PurchaseApproval` row, created **already in `approved` state** (`requested_at === responded_at`, no `pending` phase, never visible in the queue) rather than skipping the table. This keeps `ApprovedPurchaseExecutor` to its one calling convention and gives the bypass an audit trail without ever exposing a decision to make. | Skip the row entirely later if audit requirements change; the executor's contract is unaffected either way |
| 8 | **`User::trainableProfiles()` memoization** goes stale the moment a new child profile is created in the same request | Add `User::resetTrainableProfilesCache(): void` (clears the memoized property); `CreateChildProfileAction` calls it immediately after attaching the guardian pivot, before deriving the trainer-selection permitted set | Purely additive method; no consumer of the existing cache changes behaviour |

## Proposed Design

### Schema

Three additive migrations; none edits a Slice A/B file.

- **`create_purchase_approvals_table`** — `player_profile_id` (constrained, cascade), nullable
  polymorphic `approvable` (`$table->nullableMorphs('approvable')`, Epic-02 fills it), `payment_type`
  string, `amount_cents` unsigned integer, `status` string default `pending`, `requested_at`,
  `responded_at` nullable, `expires_at`, `parent_note` nullable text, timestamps. Index
  `(status, expires_at)` for the expiry sweep; index `(player_profile_id, status)` for the queue
  and the child's own view. **Not tenant-owned** — no `trainer_profile_id`, no `BelongsToTenant`
  (AD-001's third data class: reached only through the owning profile).
- **`create_notifications_table`** — Laravel's standard shape (uuid `id`, `type`,
  `notifiable_type`/`notifiable_id` morphs, `data` json, `read_at` nullable, timestamps). Nothing
  in Slices A/B used the `database` channel, so this table does not exist yet; AD-011 requires it.
- **`add_photo_path_to_player_profiles_table`** — nullable `photo_path` after `emergency_contact`.

### Enums (`app/Enums/`)

- **`ApprovalStatus`**: `Pending`, `Approved`, `Denied`, `Expired`, each a string case, with
  `isTerminal(): bool` (everything but `Pending`).
- **`PaymentType`**: `Usd`, `Token`.

### Contract and executor

- **`app/Contracts/ApprovedPurchaseExecutor.php`** — `execute(PurchaseApproval $approval): void`.
- **`app/Services/Approval/NullPurchaseExecutor.php`** — implements it: writes an audit log entry
  (`purchase-approval.executed`) and nothing else. Bound in `AppServiceProvider::register()`:
  `$this->app->bind(ApprovedPurchaseExecutor::class, NullPurchaseExecutor::class);`. The **only**
  seam for Epic-05 (AD-006) — no other stub anywhere in this slice.

### Models

- **`PurchaseApproval`** — `belongsTo(PlayerProfile::class)`, `morphTo()` as `approvable`. Casts:
  `status` → `ApprovalStatus`, `payment_type` → `PaymentType`, `requested_at`/`responded_at`/
  `expires_at` → `datetime`. `#[Fillable(['parent_note'])]` only — `status`, `player_profile_id`,
  `amount_cents`, `payment_type` are never mass-assignable (AD-016): a request-supplied `status`
  would let a child approve their own purchase. `isPending(): bool`.
- **`PlayerProfile`** — gains `purchaseApprovals(): HasMany<PurchaseApproval>`.
- **`User`** — gains `resetTrainableProfilesCache(): void` (Decision 8 above).

### Actions (`app/Actions/`)

- **`Family/CreateChildProfile`** — `handle(User $actor, ChildProfileData $data): PlayerProfile`.
  One transaction:
  1. Validate age via `birth_date` (Decision 1); run the duplicate check (Decision 2) and require
     an explicit `$confirmDuplicate` flag from the caller when it fires, mirroring how a
     Livewire component would re-submit after the user acknowledges a warning.
  2. `forceFill(['is_child' => true])` — the create-child endpoint never accepts a "self" choice;
     BR-022's self profile is created only at registration, so there is no runtime branch here.
  3. `$profile->guardians()->attach($actor, ['is_primary' => true])`.
  4. If a login was requested (Decision 3): `CreateNewUser::create()` for validation, then
     `forceFill(['is_child_account' => true])->save()`, then set the new profile's `user_id` via
     `forceFill` (never mass-assigned). **Both flags are written in the same transaction** — this
     is the first production write path for the invariant memory chunk `MEM-20260902-063160c0`
     flagged as asserted only over seeded data; a new test asserts it here at the action level,
     closing that gap rather than leaving it to the seeder alone.
  5. Call `$actor->resetTrainableProfilesCache()`.
  6. For each selected trainer id: `AssociatePlayersWithTrainer::handle($trainer, $actor, [$profile->id])`
     — reused unmodified. An empty selection is valid (declining leaves the child unassociated).
  7. Audit `child-profile.created`.
- **`Family/ManageChildTrainerAssociation`** — a single `remove(TrainerPlayer $association, User $actor): void`
  method: authorize via `TrainerPlayerPolicy::delete` at the call site (component's job, not the
  action's), `$association->delete()` (soft delete — history preserved, FR-009), audit
  `trainer-player.removed`. The RSVP-cancellation warning in FR-009's acceptance has nothing to
  act on yet — no RSVP model exists before Epic-02 — so this is a documented no-op, not invented
  logic. "Add" has no method here: both add paths reuse existing Slice B actions directly from the
  Livewire layer (see the "Already Enforced" table).
- **`Approval/RequestPurchaseApproval`** — `handle(PlayerProfile $child, ?Model $approvable, int
  $amountCents, PaymentType $paymentType): PurchaseApproval`. Guards: `$child->user_id` must be
  set (a profile-only child cannot check out — there is no login to act with); throws otherwise.
  Branches:
  - Token **and** `! $child->token_spend_requires_approval` (BR-014): `forceFill` a row directly
    into `approved` (Decision 7), call the executor, notify guardians with
    `PurchaseApprovalBypassed` (informational only).
  - Otherwise: `forceFill` a `pending` row, `expires_at = now()->addHours(48)` (NFR-009),
    notify guardians with `PurchaseApprovalRequested` (mail + database).
  Audits either way.
- **`Approval/RespondToPurchaseApproval`** — `handle(PurchaseApproval $approval, User $guardian,
  ApprovalStatus $decision, ?string $note): bool`. The correctness centre of this slice, mirroring
  `RedeemShareLink`'s idempotency pattern:
  ```
  DB::transaction(function () use (...) {
      $affected = PurchaseApproval::where('id', $approval->id)
          ->where('status', ApprovalStatus::Pending)
          ->update([
              'status' => $decision,
              'responded_at' => now(),
              'parent_note' => $note,
          ]);

      if ($affected === 1 && $decision === ApprovalStatus::Approved) {
          app(ApprovedPurchaseExecutor::class)->execute($approval->fresh());
      }

      return $affected === 1;
  });
  ```
  Returns `false` — silently, not an exception — when the row was already resolved (a double
  click, or a race with the expiry job): the second click changes nothing and the executor never
  runs twice. `DB::afterCommit()` dispatches `PurchaseApprovalResolved` to the child only when
  `$affected === 1`.
- **`app/Jobs/ExpirePurchaseApprovalsJob`** — `ShouldQueue`. `handle()`:
  `PurchaseApproval::where('status', ApprovalStatus::Pending)->where('expires_at', '<', now())
  ->chunkById(200, fn ($rows) => $rows->each(...))`, applying the **same conditional update**
  (`where('status','pending')`) per row before notifying, so a row a guardian just approved in the
  same second cannot be double-flipped to `expired`. No `TrainerContext` involved — `PurchaseApproval`
  is owner-scoped, not tenant-owned. Notifies guardians with `PurchaseApprovalExpired`.

### HTTP surface

| Route | Component | Middleware |
|---|---|---|
| `GET /family` | `Livewire\Family\Overview` | `auth,verified,role:player` |
| `GET /family/children/create` | `Livewire\Family\ChildForm` | same |
| `GET /approvals` | `Livewire\Family\PendingApprovals` | same |

`role:player` is not a new restriction invented for Slice C — it mirrors `PlayerProfilePolicy::create()`,
already enforced in Slice A, which requires `role === Player`. No `tenant` alias needed (see
Existing Context): these screens read through identity relations that are tenant-blind by design.

- **`Overview`** — lists `$actor->trainableProfiles()` joined to their `trainerAssociations()`
  (`connected_at`, current trainer, status), including the child's own row when the acting user is
  a child login viewing themselves (read-only in that case — no manage buttons, matching
  `PlayerProfilePolicy`/`TrainerPlayerPolicy`'s existing guardian-only gate). Add controls: a
  manual ShareLink-code field (calls `RedeemShareLink::forPlayer`) and a picker over the union of
  trainers not yet associated with the selected child (calls `AssociatePlayersWithTrainer`
  directly, `$via = null`). Remove control per row, gated by `$actor->can('delete', $association)`,
  with the FR-009 confirmation copy before calling `ManageChildTrainerAssociation::remove()`.
- **`ChildForm`** — the fields in Decision 1/2/3/5, single-trainer yes/no vs multi-trainer
  checklist per Decision 4, submits to `CreateChildProfile`.
- **`PendingApprovals`** — for a guardian: every `pending` (and recently resolved, for context)
  `PurchaseApproval` across `$actor->guardedPlayerProfiles()`, Approve/Deny buttons gated by
  `$actor->can('respond', $approval)`. For a child login: the same component renders only
  `$actor->playerProfile->purchaseApprovals` with no action buttons (FR-011: "child sees the
  status transition"). A `wire:poll` bell in the shared layout surfaces unread `database`
  notifications for both audiences (AD-011).

### ShareLink-blocking branch (FR-011)

In `Livewire\Join\RedeemShareLink::join()`, before the existing `authorize('trainer.associate')`
call:

```php
if ($user->is_child_account) {
    $user->playerProfile?->guardians->each(
        fn (User $guardian) => $guardian->notify(new ChildShareLinkBlocked($this->link, $user->playerProfile))
    );

    $this->blocked = true;

    return;
}
```

`render()` shows "Ask your parent to register you with this trainer" when `$blocked` is true — no
exception, no association row. The guardian's mail contains the link and a "Review Registration"
CTA; following it as themselves reaches the ordinary checklist flow already built in Slice B —
nothing new is needed on the parent's side. `ChildShareLinkBlocked` is `mail` + `database`.

**Existing test that must change**, not a regression to chase:
`RedeemShareLinkTest::a_child_account_cannot_join_an_organisation` currently asserts
`assertForbidden()`. It is rewritten to assert the blocked copy and that
`ChildShareLinkBlocked` was queued to the guardian, with `trainer_players` still empty.

### Policies

- **`PurchaseApprovalPolicy`** (auto-discovered, no registration step):
  - `view(User $user, PurchaseApproval $approval)`: `$approval->playerProfile->isGuardedBy($user)
    || $user->id === $approval->playerProfile->user_id` — the same `ownsOrIs` shape
    `PlayerProfilePolicy` already uses.
  - `respond(User $user, PurchaseApproval $approval)`: guardianship **and** `! $user->is_child_account`
    **and** `$approval->isPending()` — the same guardian-not-child shape as
    `manageTrainerAssociations`, plus the state guard so a resolved row shows no buttons at all.

### Notifications (`app/Notifications/`, all `ShouldQueue`)

`PurchaseApprovalRequested`, `PurchaseApprovalBypassed`, `PurchaseApprovalResolved`,
`PurchaseApprovalExpired`, `ChildShareLinkBlocked` — `via(): ['mail', 'database']` except
`ChildShareLinkBlocked`, which is also both channels. `toDatabase()` stores enough to render the
bell without a second query (approval id, child name, amount, status).

### Scheduler (`routes/console.php`)

```php
Schedule::job(new ExpirePurchaseApprovalsJob)->everyFifteenMinutes();
```

The first real entry in this file beyond the stock `inspire` command — the actual first exercise
of AD-008's "the scheduler is a required process" for this project, not merely a documented risk.

## Implementation Steps

Each step leaves the suite green; schema and domain logic are front-loaded ahead of UI.

### Step 1 — Schema and enums

`create_purchase_approvals_table`, `create_notifications_table`,
`add_photo_path_to_player_profiles_table` migrations; `ApprovalStatus`, `PaymentType` enums.

**Verify**: migrations run clean against MariaDB; a unit test asserts enum cases/labels.
**Reversible**: pure additions; each `down()` drops what it created.

### Step 2 — `PurchaseApproval` model, contract, executor

`PurchaseApproval` model with casts/relations; `ApprovedPurchaseExecutor` interface;
`NullPurchaseExecutor`; the binding in `AppServiceProvider`. No caller yet.

**Verify**: unit test resolves the contract from the container and asserts `execute()` writes the
expected audit-log action without throwing.

### Step 3 — Request and respond actions

`RequestPurchaseApproval` (both branches), `RespondToPurchaseApproval`, `PurchaseApprovalPolicy`,
the four approval-related notifications.

**Verify**: feature/unit tests —
- a USD request creates a `pending` row with `expires_at` 48 hours out and notifies every guardian;
- a token request with `token_spend_requires_approval = false` creates an already-`approved` row,
  calls the executor once, and sends only the bypass notification;
- a request against a profile-only child (`user_id` null) throws;
- `RespondToPurchaseApproval` approve calls the executor exactly once even when invoked twice
  concurrently on the same row (bind a spy `ApprovedPurchaseExecutor` in the container for the
  assertion); deny never calls the executor; responding to an already-resolved row returns `false`
  and changes nothing;
- `PurchaseApprovalPolicy::respond` refuses a child login and a non-guardian, and refuses a
  guardian once the row is no longer pending.

### Step 4 — Expiry job and scheduler

`ExpirePurchaseApprovalsJob`, the `Schedule::job(...)` registration.

**Verify**: a `pending` row past `expires_at` flips to `expired` and notifies; a row not yet due is
untouched; a row approved in the same tick as the sweep is not double-flipped (assert via the
conditional-update guard, not a sleep); a test asserts the job is present in
`app(Schedule::class)->events()`.

### Step 5 — Child profile creation

`photo_path` column already in from Step 1; `CreateChildProfile` action,
`User::resetTrainableProfilesCache()`, the duplicate-name+age check, the age-1–18 validation, the
optional login sub-step reusing `CreateNewUser`.

**Verify**: feature tests —
- a child profile is created and guarded by the acting parent;
- a single-trainer parent's yes/no and a multi-trainer parent's checklist each associate exactly
  the selected trainers, and declining all leaves the child with zero associations;
- an out-of-range age (0 or 19) is rejected; a similar name+birth-year within the same guardian's
  family surfaces the warning and requires confirmation to proceed, while an unrelated family's
  matching name never triggers it;
- creating a child **with** a login writes `is_child` and `is_child_account` together in one
  transaction, and a new invariant test (mirroring `ChildAccountInvariantTest` but calling the
  action directly, not seeding) asserts they can never disagree coming out of this path;
- the trainable-profiles cache-reset regression: create a child and associate it with a trainer in
  the same request, assert the `TrainerPlayer` row exists (this is the test that fails first if
  Decision 8's reset is skipped).

### Step 6 — Trainer-association management on `/family`

`ManageChildTrainerAssociation::remove()`, the manual-ShareLink-entry and pick-existing-trainer
add paths wired to the two Slice B actions, `Livewire\Family\Overview`.

**Verify**: feature tests —
- the family view lists only the acting guardian's own children, each with its trainers and
  `connected_at`;
- removing an association soft-deletes it (row survives with `deleted_at` set), the child
  disappears from that trainer's roster, and history is queryable;
- re-adding the same trainer afterward creates a **new** row rather than erroring on the unique
  index (asserted by count, not just success);
- a non-guardian and a child login both get refused on every manage/remove action;
- the manual-code add path rejects an inactive/expired code with the same copy `/join/{code}` uses.

### Step 7 — Child profile form UI, `/approvals`, and the ShareLink block

`Livewire\Family\ChildForm`, `Livewire\Family\PendingApprovals`, the `wire:poll` bell, the
`ChildShareLinkBlocked` branch in `Join\RedeemShareLink`, the three new routes.

**Verify**: feature tests —
- `/approvals` shows a guardian every guarded child's pending/resolved rows with working
  Approve/Deny, and shows a child login only its own rows with no buttons at all;
- approving from the UI transitions the row, calls the executor, and notifies the child;
- a logged-in child hitting `/join/{code}` sees the blocking copy, no `trainer_players` row is
  created, and the guardian's `ChildShareLinkBlocked` notification is queued;
- `RedeemShareLinkTest::a_child_account_cannot_join_an_organisation` is updated per the note above
  and passes against the new behaviour;
- the bell reflects an unread database notification and clears it on read.

### Step 8 — Green suite, specs, memory

Full suite, Pint, PHPStan. Append a `[TASK-001]` Slice C section to
`specs/architect-architecture.md` and update `specs/MANIFEST.md`. Capture through
`/memory-bank`: the `is_child_account`/`is_child` invariant now asserted at the action level (not
only seeded data) — this should update or supersede `MEM-20260902-063160c0` rather than duplicate
it — and the token-bypass "approved row, no pending phase" resolution as a durable convention for
whoever touches BR-014 again.

## Test Plan

**Feature** — the bulk:
- Child profile creation: happy path, age boundaries, duplicate warning (own family vs. stranger),
  single- vs multi-trainer selection, decline-all, optional login sub-step and its invariant.
- Family associations: list scoping, add via manual code, add via existing-trainer picker, remove
  (soft delete, roster visibility, re-add-after-remove), authorization refusals.
- Purchase approvals: USD request → pending → approve/deny, token bypass, expiry sweep,
  double-response idempotency, child-view read-only rendering, notification delivery per channel.
- ShareLink blocking: child login refused with the FR-011 copy, guardian notified, no association;
  the rewritten existing test.

**Unit**:
- `ApprovalStatus`/`PaymentType` cases and labels.
- `RespondToPurchaseApproval`'s conditional-update guard against a spy executor.
- `User::resetTrainableProfilesCache()` actually clears the memoized collection.

**Policy**: `PurchaseApprovalPolicy` per role (guardian, child, stranger) crossed with pending vs.
resolved state; `TrainerPlayerPolicy::delete` re-asserted from the new UI's call site.

**Database**: the `(status, expires_at)` index is exercised by the expiry test's query, not just
present; `notifications` table accepts a `database`-channel write end to end.

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

Plus `ddev exec php artisan schedule:list` to confirm `ExpirePurchaseApprovalsJob` is registered,
since AD-008 treats the scheduler as a functional requirement, not an ops afterthought.

## Risks

| Risk | Mitigation |
|---|---|
| **`trainableProfiles()` memoization hides a just-created child** from its own association step | Step 5's dedicated regression test; `resetTrainableProfilesCache()` called before every re-derivation in `CreateChildProfile` |
| **The bypass path's "approved row with no pending phase"** is a plan-level interpretation of an ambiguous brainstorming note (Decision 4 says "no approval row") | Recorded explicitly as Decision 7 with rationale; revisit if Epic-05 needs a stricter reading |
| **"Request more info" is unbuilt** despite appearing in FR-010's acceptance text | Recorded as Decision 6 (deferred, not silently dropped); Approve/Deny cover the ratified state machine completely |
| **Child photo storage has no thumbnail pass** unlike `users.photo_path` | Accepted simplification (Decision 5); revisit trigger noted for whoever builds child profile cards that expect a thumbnail |
| **The child-login creation mechanism is unspecified by any FR** | Flagged for the client as an unlisted gap (Decision 3), built as an explicit opt-in toggle rather than assumed silently |
| **Existing test `a_child_account_cannot_join_an_organisation` must be edited, not just extended** | Called out twice in this plan (Existing Context and Step 7) so it reads as an intentional behaviour change, not a break to chase down in review |
| **`ExpirePurchaseApprovalsJob` never fires if the scheduler is not running** — the same AD-008 risk Slice B carried for notifications, now with a real consequence (pending approvals never auto-deny) | `schedule:list` added to the verification checklist; the job's own conditional-update guard means a late run is still safe, just delayed |
| **A trainer or coach could, in principle, also be a guardian** (BR-022 does not forbid it) but `PlayerProfilePolicy::create()` requires `role === Player` | Pre-existing Slice A constraint, not introduced here; documented rather than silently reinforced, in case the client later asks for a trainer who is also a parent on this platform |
| **`PlayerProfilePolicy::view()`'s Slice-B TODO** (trainer reachability through an active association) remains open | Out of Slice C's FR-008–011 scope; left as-is and named here so it is not mistaken for new Slice C debt |
