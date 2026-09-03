# Sports Training Platform — Epic-01

Laravel 13 application for sports trainers, their coaches, and the players and families who train
with them. This repository currently implements **Epic-01: User Management & Authentication**
(Slices A–D) — identity, multi-tenancy, families, availability, admin tooling and GDPR compliance.

Requirements, design and the living architecture spec live in [`specs/`](specs/) and
[`tasks/TASK-001/`](tasks/TASK-001/).

## Requirements

- [DDEV](https://ddev.readthedocs.io/en/stable/users/install/) v1.24+ and Docker

Everything else — PHP 8.4, Composer, Node 24, MariaDB 11.8 — runs inside DDEV. You do **not** need
PHP installed on the host, and a host PHP older than 8.4 cannot run this project's `artisan`.

## Getting started

```bash
git clone https://github.com/rochek-innowise/laravel_php_accelerator.git
cd laravel_php_accelerator
ddev init
```

`ddev init` starts the containers, installs PHP and JavaScript dependencies, creates `.env` and the
application key, builds assets, links public storage, runs migrations and seeds the demo scenario.
It is safe to re-run: it skips the seed when the database already has users.

To wipe the database and reseed from scratch:

```bash
ddev init --fresh
```

Then open **https://laravel-accelerator.ddev.site**.

### Demo accounts

Every seeded account uses the password `password`.

| Email | Role | What it is for |
|---|---|---|
| `admin@example.test` | Super Admin | Users directory, impersonation, deactivate/reactivate, GDPR delete |
| `trainer@example.test` | Trainer | Elite Basketball Academy — roster, coaches, ShareLinks, branding |
| `trainer2@example.test` | Trainer | Northside Volleyball — the counterpart that proves tenant isolation |
| `coach@example.test` | Coach | My Times (weekly coaching schedule) |
| `parent@example.test` | Parent | Family view, children, purchase approvals, Best Times |
| `parent2@example.test` | Parent | Maya's second guardian — the co-guardianship case |
| `child@example.test` | Child login | The restricted-abilities account (FR-011) |

The seeded scenario is deliberately the hard one: **Maya Miles is a child with two guardians who
trains with both organisations.** If a change breaks tenant isolation or the family model, this is
where it becomes visible.

## Everyday commands

```bash
ddev exec php artisan test          # full suite (501 tests)
ddev exec ./vendor/bin/pint         # format to PSR-12
ddev exec ./vendor/bin/phpstan analyse   # static analysis, level 5
ddev exec php artisan route:list --except-vendor
ddev exec php artisan schedule:list
ddev npm run dev                    # Vite dev server with hot reload
ddev ssh                            # shell inside the web container
```

Run every PHP command through `ddev exec`. The host PHP is not the project's PHP.

### The queue worker is required

`QUEUE_CONNECTION` is `database`, and every notification and email in this application is
`ShouldQueue`. Without a worker they pile up in the `jobs` table and **nothing is ever delivered** —
no invitation emails, no password resets, no in-app notification bell entries. Keep one running in
its own terminal while testing:

```bash
ddev exec php artisan queue:work
```

Or drain whatever has accumulated and exit:

```bash
ddev exec php artisan queue:work --stop-when-empty
```

Outgoing mail is captured by Mailpit rather than sent — open it with `ddev mailpit`.

### Scheduled jobs

Two jobs run every 15 minutes and matter to Epic-01's behaviour:

- `ExpirePurchaseApprovalsJob` — auto-denies purchase approvals after 48 hours (FR-010).
- `CloseStaleImpersonationLogsJob` — closes impersonation logs abandoned past the 60-minute
  ceiling, so the compliance report never shows open-ended sessions (FR-012).

DDEV does not run cron. Trigger them by hand while testing:

```bash
ddev exec php artisan schedule:run
```

## What the application does

**Identity and access.** Four roles — Super Admin, Trainer, Coach, Player/Parent — with exactly one
role per user (BR-002). Registration is closed: accounts are created only by a Super Admin (trainers),
by invitation (coaches), or through a trainer's ShareLink (players). Authorization is enforced by
Policies and Gates server-side, never by hiding UI.

**Multi-tenancy.** A trainer's organisation is the tenant boundary, enforced by a fail-closed global
scope: a query that cannot resolve a tenant returns nothing rather than everything. A player may
belong to many organisations, and each association is an isolated context the user switches between.

**Families.** A parent manages child profiles, each with its own calendar, associations and
availability. A child login is a real account with a deny list of abilities it can never perform.
Purchases initiated by a child require the parent's approval and expire after 48 hours.

**Availability (FR-014/FR-015).** A weekly grid of days and time ranges, or a day marked wholly
"Not Available". Each person has a default set that applies everywhere, plus optional per-trainer
overrides that *wholly replace* the default for that one organisation. Coaches keep their own fixed
schedule, and a conflict checker reports when an assignment would clash with it.

**Admin tooling and compliance.** Super Admins impersonate users (with a colour-coded banner, a
60-minute timeout, dual-identity audit attribution, and hard-denied credential/payment actions),
deactivate and reactivate accounts, and perform GDPR erasure by anonymization — historical records
survive and render as "Deleted User", while a minimal deletion log retains a salted email hash rather
than the address.

**Branding.** A trainer uploads a logo and picks a primary colour that applies immediately to every
user in their organisation.

## Architecture notes

- Business logic lives in **Actions** (`app/Actions/`) and **Services** (`app/Services/`); controllers
  and Livewire components stay thin.
- The UI is **Livewire** with Blade components and Vite.
- Authentication is **Laravel Fortify**; registration is deliberately disabled.
- All schema changes are versioned migrations. Some DDL is MariaDB-specific (a generated column
  enforces BR-006), so the test suite runs against MariaDB, not SQLite.
- `AGENTS.md` holds the enforceable engineering policy for this repository.

## Known open items

- **SVG logos are rejected** although the requirement text lists PNG/JPG/SVG. SVG is a scriptable
  document and accepting it would be a stored-XSS vector. Awaiting client confirmation.
- **Coach event assignment is not wired.** The availability model, conflict checker and override log
  are complete; `coach_availability_overrides.event_id` is an intentional seam left unconstrained
  until Epic-02 introduces events.
