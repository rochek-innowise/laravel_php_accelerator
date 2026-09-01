---
description: Which Epic-01 Slice A requirements are held up by which tests.
---

# Validation Map — TASK-001 Slice A

Scope is Slice A only. Requirements belonging to Slices B–D are out of scope here and are not
listed as uncovered; requirements that are *in* Slice A and have no test are listed and read
`uncovered`.

The map records coverage, not correctness.

| Requirement | Source | Test | State |
| --- | --- | --- | --- |
| An active user signs in with email and password | FR-001 | `tests/Feature/Auth/LoginTest.php::test_an_active_user_can_log_in` | covered |
| A wrong password is refused | FR-001 | `tests/Feature/Auth/LoginTest.php::test_a_wrong_password_is_rejected` | covered |
| A guest cannot reach an authenticated page | FR-001 | `tests/Feature/Auth/LoginTest.php::test_a_guest_is_redirected_to_login` | covered |
| Last login timestamp is recorded | FR-001 | `tests/Feature/Auth/LoginTest.php::test_login_records_the_last_login_timestamp` | covered |
| Password reset flow works end to end | FR-002 | — | uncovered |
| Email verification gates actions, not login | FR-003, Q-01.05a | `tests/Feature/DashboardRoutingTest.php::test_an_unverified_user_reaches_their_profile_but_not_a_dashboard` | partial — the verification link round trip itself is untested |
| Each role lands on its own dashboard | FR-004 | `tests/Feature/DashboardRoutingTest.php::test_each_role_is_redirected_to_its_own_dashboard` | covered |
| A role cannot reach another role's screens | FR-004 | `tests/Feature/DashboardRoutingTest.php::test_a_player_cannot_reach_the_trainer_dashboard`, `::test_a_coach_cannot_reach_the_player_dashboard` | covered |
| Super Admin reaches every role's screens | FR-004, §6 | `tests/Feature/DashboardRoutingTest.php::test_a_super_admin_may_reach_any_role_dashboard` | covered |
| Only a Super Admin opens the user directory | FR-005 | `tests/Feature/Admin/UsersDirectoryTest.php::test_a_super_admin_can_open_the_directory`, `::test_a_non_admin_is_forbidden` | covered |
| Directory search is tool-scoped over name and email | FR-005 | `tests/Feature/Admin/UsersDirectoryTest.php::test_the_search_matches_name_and_email` | covered |
| Directory filters by role and status | FR-005 | `tests/Feature/Admin/UsersDirectoryTest.php::test_the_role_and_status_filters_narrow_the_list` | covered |
| Super Admin creates a trainer with a business profile | FR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_a_super_admin_creates_a_trainer_with_a_profile` | covered |
| The trainer invitation is sent | FR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_the_invitation_is_sent_and_carries_no_password` | covered |
| A duplicate email is a field error, never a 500 | FR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_a_duplicate_email_is_a_field_error_not_a_server_error` | covered |
| Required trainer fields are enforced | FR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_required_fields_are_enforced` | covered |
| A user edits their own profile | FR-016 | `tests/Feature/ProfileTest.php::test_a_user_updates_their_own_profile` | covered |
| A user cannot edit another user's profile | FR-016 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_user_may_update_only_their_own_account` | covered — policy level; no HTTP route exposes another user's profile yet |
| Display name derives from first and last name | FR-016 | `tests/Feature/ProfileTest.php::test_the_display_name_is_derived_from_first_and_last_name` | covered |
| Profile photo upload | FR-016 | — | uncovered — not implemented; belongs to the file-storage work |
| A deactivated account cannot log in, with the specified message | FR-017 | `tests/Feature/Auth/LoginTest.php::test_a_deactivated_user_cannot_log_in` | covered |
| A deleted account cannot log in | FR-018 | `tests/Feature/Auth/LoginTest.php::test_a_deleted_user_cannot_log_in` | covered |
| Email is unique across all users | BR-001 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_a_duplicate_email_is_a_field_error_not_a_server_error` | partial — validation is asserted; the database unique index under concurrency is not |
| Each user has exactly one role | BR-002 | — | uncovered |
| Only a Super Admin creates trainer accounts | BR-003 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_a_non_admin_cannot_open_the_form` | covered |
| No self-registration surface exists | BR-003, AD-004 | `tests/Feature/Auth/RegistrationDisabledTest.php::test_the_registration_page_does_not_exist`, `::test_registration_cannot_be_posted_to` | covered |
| Super Admin cannot impersonate another Super Admin | BR-016 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_super_admin_cannot_impersonate_another_super_admin` | covered — policy level; the route arrives in Slice D |
| Super Admin can impersonate an ordinary user | BR-016 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_super_admin_can_impersonate_an_ordinary_user` | covered |
| A non-admin cannot impersonate | BR-016 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_non_admin_cannot_impersonate` | covered |
| A child account is denied the forbidden abilities | FR-011 | `tests/Feature/Authorization/AuthorizationTest.php::test_the_child_deny_list_overrides_a_granted_ability` | covered — the test grants each ability first, so it proves the hook rather than the default deny |
| The deny list matches the eight actions FR-011 forbids | FR-011 | `tests/Unit/ChildAbilitiesTest.php::test_the_deny_list_covers_every_forbidden_action` | covered |
| A child cannot create player profiles | FR-011 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_child_account_cannot_create_player_profiles` | covered |
| Only the owning parent manages a child's trainer associations | FR-009, FR-011 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_parent_may_manage_only_their_own_childrens_associations` | covered — policy level; the screen arrives in Slice C |
| `is_child_account` always agrees with the backing profile | Design decision 2 | — | uncovered — the invariant test the design calls for is not written |
| Passwords are hashed and never mailed | NFR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_the_invitation_is_sent_and_carries_no_password` | partial — asserts the created password is not a known value; hashing itself is framework behaviour |
| Login attempts are rate limited | NFR-007 | `tests/Feature/Auth/LoginTest.php::test_repeated_attempts_are_throttled` | covered |
| State-changing requests are CSRF protected | NFR-008 | — | uncovered |
| Token TTLs: verification 24 h, reset 1 h | NFR-009 | — | uncovered |
| The directory stays paginated at scale | NFR-002 | `tests/Feature/Admin/UsersDirectoryTest.php::test_the_listing_is_paginated` | partial — page size is asserted; the 10k-row timing target is not, and a timing assertion would be flaky |
| Sensitive operations are audited with both identities | NFR-011 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_the_creation_is_audited_with_the_acting_admin` | partial — trainer creation only; impersonation and deletion are Slice D |
| WCAG 2.1 AA | NFR-012 | — | uncovered — needs the real markup, which is the frontend work |

## Notes

- The suite runs against **MariaDB**, not SQLite (AD-013), so migrations are exercised on the
  engine the application actually uses.
- `Tests\TestCase` applies `RefreshDatabase` globally; every test builds the records it
  authenticates as or mutates, so no test depends on seeded fixtures or on execution order.
- `tests/Unit/ChildAbilitiesTest.php` and `tests/Unit/RoleTest.php` extend PHPUnit's own
  `TestCase` rather than the application one — they touch no database and should not pay for a
  migration.
