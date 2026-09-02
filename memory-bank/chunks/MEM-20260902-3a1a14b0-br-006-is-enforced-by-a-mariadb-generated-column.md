---
{
  "id": "MEM-20260902-3a1a14b0",
  "title": "BR-006 is enforced by a MariaDB generated column, which pins the test engine",
  "type": "constraint",
  "status": "active",
  "scope": [
    "application"
  ],
  "tags": [
    "database",
    "mariadb",
    "coaches",
    "slice-b"
  ],
  "created": "2026-09-02",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "sources": [
    "database/migrations/2026_09_02_100002_add_active_coach_constraint_to_coach_profiles_table.php",
    "tests/Feature/Trainer/CoachInvitationTest.php",
    "phpunit.xml"
  ],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-02",
  "valid_to": null,
  "source_digests": [
    {
      "path": "database/migrations/2026_09_02_100002_add_active_coach_constraint_to_coach_profiles_table.php",
      "sha256": "b7bc2cdbf5168d49a6dd18acd18786debf9866193fdb2cfbaf41517ae4d1c599"
    },
    {
      "path": "phpunit.xml",
      "sha256": "946bb5b04a90efe12a4b223e116a51eb7645a5e6197b4d2ae27c73eb27556cb5"
    },
    {
      "path": "tests/Feature/Trainer/CoachInvitationTest.php",
      "sha256": "4e3f7f1230c20e6ac67b0db25e6b30b07ef4a00da5dd1682894a3861790c7d86"
    }
  ]
}
---

# BR-006 Is Enforced By A MariaDB Generated Column, Which Pins The Test Engine

## Durable Context

"A coach is active under exactly one trainer" is a database constraint, not application logic:
`coach_profiles.active_user_id` is a virtual column computed as `IF(status = 'active', user_id, NULL)`
with a unique index over it. NULLs do not collide in a MariaDB unique index, so any number of
`invited`/`inactive` rows coexist while at most one `active` row per coach is possible.

MariaDB has no partial unique index, which is why the constraint takes this shape. The DDL does not
parse on SQLite at all.

## Consequences

The test suite cannot be moved to SQLite: any such proposal must first answer how BR-006 is enforced.
`CoachInvitationTest::the_database_refuses_a_second_active_row_for_one_coach` inserts through the
query builder, bypassing every action, so it is the only thing that would notice the index being
dropped.

`CoachStatus::occupiesTheActiveSlot()` mirrors the generated column's expression. Changing the status
enum without the matching migration silently relaxes a database constraint.

Also unresolved: the migration's `down()` drops the index before the column (MariaDB refuses
otherwise) and **has never been executed** — `migrate:rollback` and `migrate:fresh` are blocked by
`.claude/hooks/bash-validator.sh`. See `[[MEM-20260902-fcadf3e6]]`.

## Verification

Asserted at the database level in `tests/Feature/Trainer/CoachInvitationTest.php`. Review if the
project changes database engine, or if coach status gains a fourth case.
