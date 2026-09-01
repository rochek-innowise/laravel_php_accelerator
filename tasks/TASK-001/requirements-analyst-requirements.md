# Epic-01: User Management & Authentication — Requirements

**Task**: TASK-001
**Status**: Analyzed — pending design
**Priority**: P0 (foundation, blocks Epic-02…Epic-07)

---

## Overview

Multi-role user platform for sports training organizations. Four roles (Super Admin, Trainer, Coach, Player/Parent) with strict data isolation between trainer organizations, invitation-based onboarding via ShareLinks, family accounts (parent + child profiles with approval workflows), availability management, and Super Admin support tooling (impersonation, deactivation, GDPR deletion).

This epic delivers authentication, authorization, identity and the multi-tenancy backbone every later epic builds on.

## Source

- `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md` (1100 lines, 14 user stories)
- Stack decisions confirmed with the product owner during this analysis (see below)

## Confirmed Stack Decisions

| Area | Decision | Note |
|------|----------|------|
| Backend | Laravel 13, PHP 8.4 | Bare skeleton today: only `User` model + default migrations |
| Database | MariaDB 11.8 (DDEV) | |
| Frontend | Blade + Livewire + Alpine | Monolith, no separate SPA |
| Auth | Official Laravel Livewire starter kit | Starter kits in Laravel 13 run on **Laravel Fortify** (login, registration, password reset, email verification). `laravel/breeze` is legacy — do not install it. |
| UI kit | Flux UI (ships with the Livewire starter kit) | Verify licensing: Flux has free and Pro tiers; the Pro components (date picker, editor) may be needed for availability grids |
| Assets | Vite (already configured), Tailwind | |
| Tests | PHPUnit 12 (configured) | Pest is not installed |

---

## Delivery Slices

The epic is delivered in four sequential slices. Each slice is independently demoable.

| Slice | Name | Requirements | Depends on |
|-------|------|--------------|------------|
| **A** | Auth, RBAC & user directory | FR-001…FR-006, FR-016 | — |
| **B** | Invitations & multi-tenancy | FR-007, FR-013 | A |
| **C** | Family, child accounts & approvals | FR-008…FR-011, FR-020 | A, B |
| **D** | Availability, admin tooling & compliance | FR-012, FR-014, FR-015, FR-017…FR-019 | A, B |

---

## Functional Requirements

### Slice A — Auth, RBAC & User Directory

**FR-001: Email/password authentication and session management**
- Acceptance: all four roles log in with email + password; logout ends session; session expires after inactivity; login attempts rate-limited.
- Source: §3 In Scope, §9 Authentication & Security
- Priority: High

**FR-002: Password reset flow**
- Acceptance: request reset by email, receive link, set new password; link expires after 1 hour; used links are invalidated.
- Priority: High

**FR-003: Email verification**
- Acceptance: verification email sent on registration; link expires after 24 hours; resend supported.
- Open: is verification required *before* login, or optional? (Q-01.05a — blocks final flow)
- Priority: High

**FR-004: Role-based access control (4 roles)**
- Acceptance: every user has exactly one role; post-login redirect to the role's dashboard; unauthorized features return 403 and are hidden in the UI; enforcement lives on the server, UI hiding is cosmetic only.
- Priority: High

**FR-005: Users tool (Super Admin global directory)**
- Acceptance: paginated list of all users with role/status filters and tool-scoped search (no global search); row actions: Edit, Deactivate/Reactivate, Delete, Impersonate.
- Note: listed in §3 In Scope but has **no dedicated user story** — acceptance criteria inferred.
- Priority: High

**FR-006 (US-01.01): Super Admin creates trainer account**
- Acceptance: create form with business name, trainer name, email, phone; invite email with setup link (or temporary password forcing a reset on first login); unique-email validation with a clear duplicate error; new trainer appears as "Active"; creation is audit-logged.
- Business rule: only Super Admin can create trainers — no trainer self-registration.
- Priority: High

**FR-016 (US-01.11): User edits own profile**
- Acceptance: editable — first/last name, phone, photo; read-only — email, role, skill level, created date; role-specific fields (player: school/jersey; parent: emergency contact; coach: bio/credentials/certifications/public flag; trainer: business/org details).
- Acceptance: photo uploaded to configured filesystem disk, thumbnail generated.
- Priority: High

### Slice B — Invitations & Multi-Tenancy

**FR-007 (US-01.02): Player registers via ShareLink**
- Acceptance: `/join/{code}` → registration or login; on completion the player is associated with the link's trainer, a player profile appears in that trainer's roster, confirmation email is sent.
- Acceptance (existing account, logged in): instant association, no duplicate account, redirect to trainer's events.
- Acceptance (parent with children): prompt "Who will train with [Trainer]?" with a checklist of Parent (Me) + children; only selected members are associated.
- Acceptance (separated views): a trainer-context switcher in navigation; each context exposes fully isolated data (calendar, tokens, content, reservations); context persists across the session; no unified cross-trainer view.
- Priority: High

**FR-013 (US-01.08): Trainer invites coach**
- Acceptance: invite by email with optional name/message; unique single-use ShareLink expiring in 7 days; invitation status visible (Pending / Accepted / Expired) with resend; coach appears in the trainer's Coaches list after acceptance.
- Business rule: a coach can be active under exactly one trainer — attempting to accept a second active invitation is rejected with an explicit error.
- Priority: High

### Slice C — Family, Child Accounts & Approvals

**FR-008 (US-01.03): Parent creates child profile**
- Acceptance: add child with name, age, gender, optional school/photo, marked "Child" vs "Self".
- Acceptance (trainer selection): single-trainer parent → yes/no prompt; multi-trainer parent → checklist; declining leaves the child unassociated.
- Acceptance: each child has separate calendar, RSVP status, attendance and availability **per trainer**; parent switches children via the context selector.
- Validation: name/age/gender required; age 1–18; warn on a similar name+age duplicate.
- Priority: High

**FR-009 (US-01.04): Parent manages child-trainer associations**
- Acceptance: family view listing children with their trainers and association dates.
- Acceptance (add): via manual ShareLink entry or by picking from the parent's existing trainers.
- Acceptance (remove): confirmation warning that upcoming RSVPs are cancelled; association soft-deleted (history preserved); child disappears from the trainer's roster.
- Priority: High

**FR-010 (US-01.05): Child purchase requires parent approval**
- Acceptance (USD): child checkout → "Pending Parent Approval"; parent notified by email + in-app; parent approves (payment processed), denies (child notified), or requests more info; child sees the status transition.
- Acceptance (tokens): per-child setting "Allow token spending without approval", default OFF; when OFF the USD workflow applies; when ON the token spend is immediate and the parent gets an informational notification.
- Acceptance: pending requests auto-deny after 48 hours with notification.
- **Blocked**: payment execution and tokens belong to Epic-05, events to Epic-02. See Gap G-03.
- Priority: High

**FR-011 (US-01.06): Child login with constraints**
- Acceptance (allowed): browse eligible events, RSVP and cancel RSVP (via approval), view purchased content and own progress, update basic profile, view tokens read-only, switch own trainer contexts.
- Acceptance (denied): add trainers, add/remove payment methods, purchase tokens, complete purchases without approval, delete the account, change trainer associations, view the parent's training data.
- Acceptance (ShareLink blocking): a logged-in child clicking a ShareLink sees "Ask your parent to register you with this trainer"; the parent receives an email containing the link and a "Review Registration" CTA; no association is created.
- Priority: High

**FR-020: Camp-to-User conversion (Epic-08 integration)**
- Listed in §3 In Scope with no user story and no acceptance criteria: after a camp/evaluation form submission, prompt account creation, pre-fill from the submission, auto-assign the trainer, or email a ShareLink for later.
- **Blocked**: Epic-08 is not listed in the epic's dependency graph (§5 names only Epic-02…Epic-07). See Gap G-01.
- Priority: Deferred until specified

### Slice D — Availability, Admin Tooling & Compliance

**FR-014 (US-01.09): Player/parent sets availability ("Best Times")**
- Acceptance: weekly grid of days × time ranges; per day mark available ranges or "Not Available"; parents set availability separately per child via the profile switcher; confirmation on save.
- Acceptance (trainer view): availability indicator in event creation and CRM; filter "players available on [day/time]"; player card summary ("Best Times: Mon 5-8pm").
- Conflict: US-01.03 requires availability **per trainer**, §8 data requirements define no trainer scope. See Gap G-04.
- Priority: Medium

**FR-015 (US-01.10): Coach sets My Times, trainer overrides conflicts**
- Acceptance: recurring weekly schedule, multiple slots per day.
- Acceptance (assignment): assigning a coach at a conflicting time shows a warning; the trainer may continue only after entering a reason; the override is logged with event, coach, reason and the overriding trainer; the coach is not blocked and may accept or request a change.
- **Partially blocked**: event assignment belongs to Epic-02 — deliver the availability model, the conflict check service and the override log; wire the UI when events exist.
- Priority: Medium

**FR-012 (US-01.07): Super Admin impersonates user**
- Acceptance: Impersonate action with a confirmation modal; the session switches to the target user's exact view, navigation and permissions; a sticky, colour-coded banner shows "Viewing as [User] | Exit Impersonation"; exit restores the Super Admin session.
- Security: impersonating another Super Admin is rejected; every impersonation is logged (admin, target, start, end, duration); the impersonation session expires after 1 hour; an "Impersonation History" report is available.
- Priority: High

**FR-017 (US-01.12): Super Admin deactivates user**
- Acceptance: confirmation modal explaining history preservation; status → Inactive; login blocked with "Account deactivated. Contact support."; the user remains visible in analytics, past rosters and CRM (marked Inactive); Super Admin can reactivate.
- Priority: Medium

**FR-018 (US-01.13): Super Admin deletes user (GDPR)**
- Acceptance: destructive confirmation; PII anonymized — name → "Deleted User", email → `deleted_{id}@example.com`, phone → null, photo → default avatar, personal identifiers cleared.
- Acceptance: historical rows survive and render as "Deleted User"; analytics aggregates are unchanged; status → Deleted; reactivation is impossible; the deletion is logged (original id, actor, timestamp, reason).
- Conflict: §8 also requires a "backup of original data for legal compliance", which contradicts irreversible erasure. See Gap G-05.
- Priority: Medium

**FR-019 (US-01.14): Trainer customizes portal branding**
- Acceptance: logo upload (PNG/JPG/SVG, ≤2MB, recommended 200×200, auto-resize) with preview; primary colour picker with live preview and reset-to-default; branding applies immediately for every user in that trainer's organization.
- Note: §3 refers to this as "US-01.12"; the actual story is US-01.14. See Gap G-06.
- Priority: Low

---

## Non-Functional Requirements

| ID | Requirement | Metric |
|----|-------------|--------|
| NFR-001 | Dashboard load | < 2 s |
| NFR-002 | Users list with 10,000 users | < 3 s, paginated |
| NFR-003 | Profile save | < 1 s |
| NFR-004 | ShareLink registration | < 2 s, 100 concurrent registrations |
| NFR-005 | Platform capacity | 1,000 concurrent users |
| NFR-006 | Password storage | Framework default hashing (bcrypt/argon2), never reversible |
| NFR-007 | Auth rate limiting | Login and password-reset endpoints throttled against brute force |
| NFR-008 | CSRF | Protection on every state-changing request |
| NFR-009 | Token TTLs | Email verification 24 h, password reset 1 h, impersonation session 1 h, coach invite link 7 days, purchase approval 48 h |
| NFR-010 | Tenant isolation | 0% data leakage between trainer organizations, enforced server-side |
| NFR-011 | Audit logging | Impersonation, user deletion, trainer creation, availability overrides |
| NFR-012 | Accessibility | WCAG 2.1 AA: keyboard navigation, screen readers, contrast, focus indicators |
| NFR-013 | Responsive | Works on all screen sizes, touch-friendly, mobile-optimized forms and uploads |
| NFR-014 | Onboarding speed | Trainer onboards a player or coach in < 5 minutes |

---

## Business Rules

| ID | Rule | Source |
|----|------|--------|
| BR-001 | Email is unique across all users | §9 |
| BR-002 | Each user has exactly one role | §9 |
| BR-003 | Only Super Admin creates trainer accounts; trainers cannot self-register | §9 |
| BR-004 | Trainers see only their own organization's data | §9 |
| BR-005 | A player may associate with many trainers; each association is a separate, isolated context | §9 |
| BR-006 | A coach is active under exactly one trainer at a time | §9 |
| BR-007 | Connecting an existing player to a new trainer creates an association, never a duplicate account | §9 |
| BR-008 | Player ShareLinks are static: unlimited uses, no expiry | §9 |
| BR-009 | Coach ShareLinks are unique: single use, 7-day expiry | §9 |
| BR-010 | All players under 18 require parent-managed accounts | §9 — conflicts with US-01.06 open question, see G-02 |
| BR-011 | The parent owns all family contact information | §9 |
| BR-012 | Each child has a separate calendar, RSVP status and Best Times | §9 |
| BR-013 | USD purchases by a child always require parent approval | §9 |
| BR-014 | Token spending by a child requires approval unless the parent enables per-child bypass | §9 |
| BR-015 | Approval requests expire after 48 hours and auto-deny | §9 |
| BR-016 | Super Admin cannot impersonate another Super Admin | §9 |
| BR-017 | Deactivation blocks login and preserves all history | §9 |
| BR-018 | Deletion anonymizes PII permanently while preserving historical rows and analytics totals | §9 |
| BR-019 | Availability is advisory for scheduling, never a hard restriction | §9 |
| BR-020 | A coach availability conflict may be overridden only with a recorded reason | §9 |
| BR-021 | ShareLink usage is tracked per link for later analytics (Epic-06) | §9 |
| BR-022 | A parent account is itself a player account (a parent may train) | §7 US-01.03 |
| BR-023 | Child-trainer associations are explicit, never implicit (except the single-trainer prompt) | §7 US-01.03 |

---

## Task Breakdown

### Eloquent Models

| Model | Key attributes | Relations | Slice |
|-------|----------------|-----------|-------|
| `User` | email, password, role (enum), status (enum: active/inactive/deleted), email_verified_at, last_login_at, first_name, last_name, phone, photo_path | hasOne trainerProfile / coachProfile; hasMany playerProfiles; hasMany impersonationLogs | A |
| `TrainerProfile` | user_id, business_name, address, website, description, logo_path, primary_color | belongsTo user; hasMany coaches, shareLinks, playerAssociations | A / D |
| `CoachProfile` | user_id, trainer_profile_id, bio, credentials, certifications, is_public, joined_at, status | belongsTo user, trainerProfile; hasMany availabilities | B |
| `PlayerProfile` | owner_user_id, child_login_user_id (nullable), name, birth_date, gender, skill_level, school, jersey_number, is_child, emergency_contact, token_spend_requires_approval | belongsTo owner (User); hasMany trainerAssociations, availabilities, purchaseApprovals | C |
| `TrainerPlayer` | trainer_profile_id, player_profile_id, share_link_id, connected_at, status, deleted_at | belongsTo trainerProfile, playerProfile, shareLink | B |
| `ShareLink` | code (unique), type (player_static/coach_unique), trainer_profile_id, created_by, target_email, expires_at, max_uses, uses_count, is_active | belongsTo trainerProfile, creator; hasMany trainerPlayers | B |
| `Availability` | availableFor (morph: PlayerProfile/CoachProfile), trainer_profile_id (nullable — see G-04), day_of_week, start_time, end_time, is_available | morphTo availableFor | D |
| `PurchaseApproval` | player_profile_id, parent_user_id, approvable (morph — Epic-02/05), amount_cents, payment_type (usd/token), status, requested_at, responded_at, expires_at, parent_note | belongsTo playerProfile, parent | C |
| `ImpersonationLog` | admin_user_id, target_user_id, started_at, ended_at, duration_seconds, ip_address | belongsTo admin, target | D |
| `CoachAvailabilityOverride` | event_id (Epic-02), coach_profile_id, trainer_profile_id, reason, created_at | belongsTo coachProfile, trainerProfile | D |
| `UserDeletionLog` | original_user_id, original_email, deleted_by, reason, deleted_at | belongsTo deletedBy | D |

### Actions / Services

| Class | Purpose | Slice |
|-------|---------|-------|
| `CreateTrainerAccountAction` | Create user + trainer profile, dispatch invite, write audit entry | A |
| `TrainerContext` (service) | Resolve, persist and switch the active trainer context for the session | B |
| `GenerateShareLinkAction` | Issue a static player link or a unique coach link | B |
| `RedeemShareLinkAction` | Validate code (active, unexpired, uses left, role fit), branch to register/associate/block | B |
| `AssociatePlayerWithTrainerAction` | Create `TrainerPlayer`, increment link usage, guard against duplicates | B |
| `InviteCoachAction` | Issue coach link, send invite, enforce single-active-trainer rule | B |
| `CreateChildProfileAction` | Create child profile, optional child login, apply trainer selection | C |
| `ManageChildTrainerAssociationAction` | Add/remove a child-trainer link, cancel affected RSVPs, soft-delete history | C |
| `RequestPurchaseApprovalAction` | Create a pending approval, notify the parent, set the 48 h expiry | C |
| `RespondToPurchaseApprovalAction` | Approve/deny, hand off to payment execution (Epic-05), notify the child | C |
| `ExpirePurchaseApprovalsJob` | Scheduled auto-deny of stale requests | C |
| `SaveAvailabilityAction` | Replace a subject's weekly availability set | D |
| `CheckCoachAvailabilityService` | Report conflicts for a proposed assignment window | D |
| `OverrideCoachAvailabilityAction` | Record an override with a mandatory reason | D |
| `StartImpersonationAction` / `StopImpersonationAction` | Swap the session identity, guard the Super Admin rule, open/close the log entry | D |
| `DeactivateUserAction` / `ReactivateUserAction` | Toggle status, block or restore login | D |
| `AnonymizeUserAction` | Irreversible PII scrub + deletion log | D |
| `UpdateTrainerBrandingAction` | Store logo, validate and persist the colour, bust the branding cache | D |

### Routes, Controllers & Livewire Components

| Route | Handler | Purpose | Slice |
|-------|---------|---------|-------|
| Fortify auth routes | starter kit | Login, register, password reset, email verification | A |
| `/dashboard` | `DashboardController` | Role-dispatched dashboard | A |
| `/profile` | `Livewire\ProfileForm` | Own-profile editing with photo upload | A |
| `/admin/users` | `Livewire\Admin\UsersTable` | Directory, filters, tool-scoped search, row actions | A |
| `/admin/users/create` | `Livewire\Admin\CreateTrainerForm` | Trainer creation | A |
| `/join/{code}` | `ShareLinkController@show` | ShareLink landing: register / associate / block child | B |
| `/trainer/share-links` | `Livewire\Trainer\ShareLinks` | Generate and list links | B |
| `/trainer/coaches` | `Livewire\Trainer\Coaches` | Invite coaches, track invitation status | B |
| `context-switch` | `TrainerContextController` | Switch the active trainer context | B |
| `/family` | `Livewire\Family\Overview` | Children, their trainers, add/remove associations | C |
| `/family/children/create` | `Livewire\Family\ChildForm` | Create a child profile with trainer selection | C |
| `/approvals` | `Livewire\Family\PendingApprovals` | Parent approval queue | C |
| `/availability` | `Livewire\Availability\Grid` | Player/parent Best Times (per child, per trainer) | D |
| `/coach/my-times` | `Livewire\Availability\Grid` | Coach weekly availability | D |
| `/admin/impersonate/{user}` | `ImpersonationController` | Start impersonation | D |
| `/impersonate/stop` | `ImpersonationController@stop` | Exit impersonation | D |
| `/admin/impersonation-history` | `Livewire\Admin\ImpersonationHistory` | Audit report | D |
| `/trainer/branding` | `Livewire\Trainer\Branding` | Logo + colour with live preview | D |

### Cross-Cutting Backend Tasks

- [ ] Install the official Livewire starter kit; verify the Fortify feature set (registration, reset, verification, session) and Flux UI licensing.
- [ ] `Role` and `UserStatus` enums; extend the users migration (role, status, name parts, phone, photo, last_login_at).
- [ ] Policies per model (`UserPolicy`, `PlayerProfilePolicy`, `TrainerPlayerPolicy`, `ShareLinkPolicy`, `CoachProfilePolicy`, `PurchaseApprovalPolicy`, `AvailabilityPolicy`) + Gates for Super-Admin-only capabilities.
- [ ] `EnsureTrainerContext` middleware + a `BelongsToTrainerContext` global scope, so tenant filtering is the default, not a per-query decision.
- [ ] `ChildAccount` middleware/gate bundle enforcing the FR-011 deny list server-side.
- [ ] Form Requests (or Livewire validation rules) for every write path; centralize phone/age/hex-colour rules.
- [ ] Notifications: TrainerInvited, CoachInvited, PlayerWelcome, ChildShareLinkBlocked, PurchaseApprovalRequested, PurchaseApprovalResolved, PurchaseApprovalExpired, AccountDeactivated.
- [ ] Filesystem disk configuration for profile photos and trainer logos + image resizing.
- [ ] Login throttling configuration and an audit-log target for FR/NFR-011.
- [ ] Factories and seeders for every model; a demo seeder covering the multi-trainer parent-with-children scenario.

### Frontend Tasks (Blade / Livewire / Alpine)

- [ ] Role-specific dashboard layouts sharing one app shell.
- [ ] Trainer context switcher in navigation, with the parent variant ("Me" + children), the parent-who-doesn't-train variant, and the child variant.
- [ ] Impersonation banner: sticky, colour-coded, always visible, exits in one click.
- [ ] Users table: server-side pagination and filtering sized for 10k rows (NFR-002).
- [ ] Availability grid: keyboard-operable day × time selection with multiple ranges per day (accessibility risk — see G-08).
- [ ] Branding: live preview via CSS custom properties driven by the trainer's primary colour.
- [ ] Destructive-action confirmation modals (deactivate, delete, remove child from trainer) with the exact warning copy from the spec.
- [ ] Trainer branding applied to the portal shell for every user in the organization.
- [ ] Accessibility pass: semantic markup, labels, focus order, contrast (NFR-012); responsive pass (NFR-013).

### Testing Tasks

- [ ] Feature tests per user story: registration via ShareLink (new and existing account), multi-trainer association, parent creates child → child requests purchase → parent approves, trainer creates coach invite (expiry, single use), coach single-trainer enforcement, impersonation start/exit/logging, deactivation preserves history, deletion anonymizes PII, availability set and viewed by trainer.
- [ ] Authorization tests: every role against every route; the child deny list; Super-Admin-on-Super-Admin impersonation rejection.
- [ ] Tenant isolation tests: trainer A cannot read, list or mutate trainer B's players, coaches, links or availability.
- [ ] Validation tests: unique email, age 1–18, phone format, hex colour, logo type/size, ShareLink expiry and use limits.
- [ ] Unit tests: `CheckCoachAvailabilityService` conflict matrix, approval expiry logic, anonymization mapping.
- [ ] Rate-limiting test on login; token TTL tests for reset, verification, invitation, approval and impersonation.
- [ ] Livewire component tests for the context switcher, availability grid and approval queue.

---

## Validation Checklist

- [x] All functional requirements mapped to tasks
- [x] Happy path covered
- [x] Error cases identified (duplicate email, expired/used links, coach already assigned, denied approval)
- [x] Edge cases considered (parent who also trains, child with multiple trainers, deletion of a user with history, override with reason)
- [x] Security requirements addressed (RBAC, tenancy, impersonation guardrails, throttling, CSRF, token TTLs, audit)
- [x] Performance requirements noted (NFR-001…NFR-005)
- [x] Testing strategy defined
- [ ] Open questions resolved — **7 blockers below**

---

## Gap Analysis

| ID | Gap | Impact | Needs |
|----|-----|--------|-------|
| G-01 | Camp-to-User conversion (FR-020) is In Scope but has no user story, no acceptance criteria, and Epic-08 is absent from the §5 dependency graph | Cannot be estimated or built | Client: acceptance criteria + Epic-08 timeline |
| G-02 | §9 states all under-18 players require parent-managed accounts; US-01.06's open question asks whether 16–18 year olds may be independent | Determines whether a standalone minor account type exists — affects the data model | Client decision + COPPA/GDPR-minors legal review |
| G-03 | FR-010 approval workflow depends on events (Epic-02) and payments/tokens (Epic-05), neither of which exists | The workflow cannot be completed end to end in this epic | Decide: build the approval domain against a polymorphic `approvable` with a stubbed payment executor, or defer FR-010 to Epic-05 |
| G-04 | US-01.03 requires availability **per trainer per child**; §8 defines availability without a trainer dimension | Wrong schema now means a migration later | Confirm: is availability global per player, or scoped per trainer association? |
| G-05 | §8 requires a "backup of original data for legal compliance" while US-01.13 requires irreversible anonymization | Directly contradictory under GDPR Art. 17 | Legal decision on the retained minimum (deletion log with original id + email only, as currently drafted) |
| G-06 | §3 and the footer call portal branding US-01.12 and count 12 stories; the file actually contains 14, and US-01.12 is deactivation | Cross-references break traceability | Editorial fix in the source spec |
| G-07 | Q-01.05 is used for two different questions: "email verification required before login?" (§12) and "may 16–18 year olds hold independent accounts?" (US-01.06) | Ambiguous question tracking | Editorial fix; both need answers |
| G-08 | "Separated views / no unified view" versus the parent context selector, which necessarily lists every context in one menu | Defines how much cross-trainer data the shell may show | Confirm what the switcher may reveal (names only? counts? notifications?) |
| G-09 | US-01.06 cross-references the purchase approval story as US-01.04; the approval story is US-01.05 | Minor traceability defect | Editorial fix |
| G-10 | Skill levels (Q-01.01) and age groups (Q-01.02) are undefined, yet skill level is a stored, trainer-set player field | Blocks the player profile schema and CRM filters | Client: enumerate values or confirm free text for MVP |
| G-11 | Coach transfer between trainers is unspecified — "one trainer at a time" says nothing about leaving trainer A for trainer B | Blocks the coach lifecycle | Confirm: does the old association become inactive with history retained? |
| G-12 | Impersonation expiry is defined for the impersonated session; the Super Admin's own session state during and after impersonation is unspecified | Security-relevant | Confirm the restore semantics and whether admin actions are attributable to both ids |

## Open Questions (from the source spec)

| ID | Question | Priority | Owner |
|----|----------|:--------:|-------|
| Q-01.01 | Skill level definitions (fixed set or custom?) | P2 | Client |
| Q-01.02 | Age group definition (birth year, ranges, grade levels?) | P2 | Client |
| Q-01.04 | Full list of required automated emails | P1 | Client |
| Q-01.05a | Email verification required before login, or optional? | P1 | Client |
| Q-01.05b | May 16–18 year olds hold independent accounts? | P1 | Client + legal |
| Q-01.06 | Should a coach be notified when an availability conflict is overridden? | P2 | Client |
| Q-01.07 | Session lifetime (1 / 7 / 30 days)? | P2 | Client |

---

## Next Steps (suggested — not executed)

1. Resolve the P1 blockers: G-01, G-02, G-03, G-04, G-05, Q-01.04, Q-01.05a.
2. `/brainstorm [TASK-001]` — turn the multi-tenancy, context-switching and child-account model into a concrete design.
3. `/architect [TASK-001]` — decide role storage (enum + Policies vs. a permissions package), the tenant-scoping mechanism, and the profile-table layout.
4. `/writing-plans [TASK-001]` — plan Slice A first; it unblocks everything else.
