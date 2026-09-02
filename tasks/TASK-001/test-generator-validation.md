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
| A refused login records no timestamp | FR-001, FR-017 | `tests/Feature/Auth/LoginTest.php::test_a_refused_login_does_not_record_a_login_timestamp` | covered |
| Password reset flow works end to end | FR-002 | `tests/Feature/Auth/PasswordResetTest.php` | covered — full round trip, stale token refused, and no link mailed to a non-active account |
| Email verification gates actions, not login | FR-003, Q-01.05a | `tests/Feature/DashboardRoutingTest.php::test_an_unverified_user_reaches_their_profile_but_not_a_dashboard`, `tests/Feature/Auth/EmailVerificationTest.php` | covered — signed-link round trip, unsigned link refused, resend works |
| The verification link expires 24 hours out | FR-003, NFR-009 | `tests/Feature/Auth/EmailVerificationTest.php::test_the_verification_expiry_is_configured_for_24_hours`, `::test_a_generated_verification_link_expires_24_hours_out` | covered — the configured value and the generated signature's `expires` timestamp are both asserted |
| Each role lands on its own dashboard | FR-004 | `tests/Feature/DashboardRoutingTest.php::test_each_role_is_redirected_to_its_own_dashboard` | covered |
| A role cannot reach another role's screens | FR-004 | `tests/Feature/DashboardRoutingTest.php::test_a_player_cannot_reach_the_trainer_dashboard`, `::test_a_coach_cannot_reach_the_player_dashboard` | covered |
| Super Admin reaches every role's screens | FR-004, §6 | `tests/Feature/DashboardRoutingTest.php::test_a_super_admin_may_reach_any_role_dashboard` | covered |
| Only a Super Admin opens the user directory | FR-005 | `tests/Feature/Admin/UsersDirectoryTest.php::test_a_super_admin_can_open_the_directory`, `::test_a_non_admin_is_forbidden` | covered |
| Directory search is tool-scoped over name and email | FR-005 | `tests/Feature/Admin/UsersDirectoryTest.php::test_the_search_matches_name_and_email`, `::test_the_search_matches_a_full_name` | covered — the composed name is matched as one string, and wildcards in the term are escaped (`::test_a_wildcard_in_the_search_term_is_escaped`) |
| Directory filters by role and status | FR-005 | `tests/Feature/Admin/UsersDirectoryTest.php::test_the_role_and_status_filters_narrow_the_list` | covered |
| The Edit row action opens and saves another user's profile | FR-005 | `tests/Feature/Admin/EditUserTest.php::test_a_super_admin_edits_another_users_common_fields` | covered |
| A non-admin is refused the edit route; a guest is redirected | FR-005 | `tests/Feature/Admin/EditUserTest.php::test_a_non_admin_gets_403_on_the_edit_route`, `::test_a_guest_is_redirected_to_login` | covered |
| Role and status stay read-only on the admin edit form | FR-005, Slice D | `tests/Feature/Admin/EditUserTest.php::test_role_and_status_cannot_be_changed_through_the_admin_edit_form` | covered |
| Super Admin creates a trainer with a business profile | FR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_a_super_admin_creates_a_trainer_with_a_profile` | covered |
| The trainer invitation is sent | FR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_the_invitation_is_sent_and_carries_no_password` | covered |
| The invitation carries neither a password nor a token | FR-006, NFR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_the_invitation_carries_no_token_and_cannot_expire` | covered — the mail points at the password-request form, so nothing sensitive reaches the queue payload and the invitation cannot go stale |
| A duplicate email is a field error, never a 500 | FR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_a_duplicate_email_is_a_field_error_not_a_server_error` | covered |
| Required trainer fields are enforced | FR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_required_fields_are_enforced` | covered |
| A user edits their own profile (first/last name, phone) | FR-016 | `tests/Feature/ProfileTest.php::test_a_user_updates_their_own_profile` | covered |
| A user cannot edit another user's profile | FR-016 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_user_may_update_only_their_own_account` | covered — policy level; the only HTTP route that reaches another user's profile is the Super Admin edit screen, which `EditUserTest` covers separately |
| Display name derives from first and last name | FR-016 | `tests/Feature/ProfileTest.php::test_the_display_name_is_derived_from_first_and_last_name` | covered |
| A player edits school and jersey number on their self profile | FR-016 | `tests/Feature/RoleSpecificProfileFieldsTest.php::test_a_player_edits_school_and_jersey_number` | covered |
| A parent (a player who owns a child profile) edits emergency contact | FR-016 | `tests/Feature/RoleSpecificProfileFieldsTest.php::test_a_parent_edits_emergency_contact_on_their_self_profile`, `::test_a_non_parent_player_cannot_write_emergency_contact` | covered — "parent" is derived as owning at least one child `PlayerProfile`, re-checked server-side at save time rather than trusted from the client |
| Skill level renders read-only and is not writable through the form | FR-016 | `tests/Feature/RoleSpecificProfileFieldsTest.php::test_skill_level_is_read_only_and_not_writable_through_the_form` | covered |
| A coach edits bio, credentials, certifications and the public flag | FR-016 | `tests/Feature/RoleSpecificProfileFieldsTest.php::test_a_coach_edits_bio_credentials_certifications_and_public_flag` | covered |
| A trainer edits their business profile | FR-016 | `tests/Feature/RoleSpecificProfileFieldsTest.php::test_a_trainer_edits_their_business_profile` | covered |
| Role-specific validation: bad URL, over-length text | FR-016 | `tests/Feature/RoleSpecificProfileFieldsTest.php::test_an_invalid_website_url_is_rejected`, `::test_over_length_text_is_rejected` | covered |
| A user with no matching profile sees only the common fields; a role sees only its own set | FR-016 | `tests/Feature/RoleSpecificProfileFieldsTest.php::test_a_user_with_no_matching_profile_sees_only_common_fields`, `::test_a_coach_sees_only_the_coach_field_set` | covered |
| Profile photo upload | FR-016 | `tests/Feature/ProfilePhotoTest.php` | covered — upload, square thumbnail, replacement, removal |
| Photo validation rejects non-images | FR-016, NFR-006 | `tests/Feature/ProfilePhotoTest.php::test_a_non_image_upload_is_rejected_by_validation`, `::test_an_oversized_upload_is_rejected` | covered |
| A file that sniffs as an image but cannot decode fails safely | FR-016 | `tests/Feature/ProfilePhotoTest.php::test_a_renamed_script_fails_decoding_and_leaves_no_file` | covered — field error, not a 500, and no file left on disk |
| Photos are served only through a signed, authorized route | FR-016, AD-020 | `tests/Feature/ProfilePhotoTest.php::test_the_photo_is_served_through_a_signed_route`, `::test_an_unsigned_request_is_refused`, `::test_a_signed_link_does_not_let_a_stranger_through`, `::test_an_expired_link_is_refused` | covered |
| A deactivated account cannot log in, with the specified message | FR-017 | `tests/Feature/Auth/LoginTest.php::test_a_deactivated_user_cannot_log_in` | covered |
| A deactivated account loses an already-open session | FR-017 | `tests/Feature/Auth/AccountStatusTest.php::test_a_session_deactivated_mid_flight_is_terminated`, `::test_the_remember_token_is_cycled_on_lockout`, `::test_a_deactivated_user_cannot_reach_fortify_profile_endpoints` | covered |
| A deleted account cannot log in | FR-018 | `tests/Feature/Auth/LoginTest.php::test_a_deleted_user_cannot_log_in` | covered |
| A deleted account loses an already-open session | FR-018 | `tests/Feature/Auth/AccountStatusTest.php::test_a_deleted_account_is_locked_out_mid_session` | covered |
| Email is unique across all users | BR-001 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_a_duplicate_email_is_a_field_error_not_a_server_error` | partial — validation is asserted; the database unique index under concurrency is not |
| Each user has exactly one role | BR-002 | — | uncovered |
| Role, status and child flag are not mass-assignable | BR-002, NFR-006 | `tests/Feature/Authorization/MassAssignmentTest.php` | covered |
| Only a Super Admin creates trainer accounts | BR-003 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_a_non_admin_cannot_open_the_form` | covered |
| No self-registration surface exists | BR-003, AD-004 | `tests/Feature/Auth/RegistrationDisabledTest.php::test_the_registration_page_does_not_exist`, `::test_registration_cannot_be_posted_to` | covered |
| Super Admin cannot impersonate another Super Admin | BR-016 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_super_admin_cannot_impersonate_another_super_admin` | covered — policy level; the route arrives in Slice D |
| Super Admin can impersonate an ordinary user | BR-016 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_super_admin_can_impersonate_an_ordinary_user` | covered |
| A non-admin cannot impersonate | BR-016 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_non_admin_cannot_impersonate` | covered |
| A child account is denied the forbidden abilities | FR-011 | `tests/Feature/Authorization/AuthorizationTest.php::test_the_child_deny_list_overrides_a_granted_ability` | covered — the test grants each ability first, so it proves the hook rather than the default deny |
| The deny list matches the eight actions FR-011 forbids | FR-011 | `tests/Unit/ChildAbilitiesTest.php::test_the_deny_list_covers_every_forbidden_action` | covered |
| A child cannot create player profiles | FR-011 | `tests/Feature/Authorization/AuthorizationTest.php::test_a_child_account_cannot_create_player_profiles` | covered |
| Only a guardian manages a child's trainer associations | FR-009, FR-011 | `tests/Feature/Authorization/GuardianshipTest.php`, `tests/Feature/Authorization/AuthorizationTest.php::test_a_parent_may_manage_only_their_own_childrens_associations` | covered — a child with its own login can view but never manage (AD-019) |
| A child can have two guardians | AD-019, BR-022 | `tests/Feature/Authorization/GuardianshipTest.php` | covered — both directions of the relation, plus the seeded two-guardian case |
| A guardian edits the emergency contact of each child | FR-016 | `tests/Feature/RoleSpecificProfileFieldsTest.php::test_a_guardian_edits_the_emergency_contact_of_each_child`, `::test_a_submitted_child_id_outside_the_guardianship_is_ignored` | covered — including a guardian with no self profile |
| `is_child_account` always agrees with the backing profile | Design decision 2 | `tests/Feature/Authorization/ChildAccountInvariantTest.php` | covered — asserted over seeded data, the only place both sides are written together |
| Passwords are hashed and never mailed | NFR-006 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_the_invitation_is_sent_and_carries_no_password` | partial — asserts the created password is not a known value; hashing itself is framework behaviour |
| TrainerProfilePolicy: owner writes, other organisations refused | FR-007, NFR-010 | `tests/Feature/Authorization/TrainerProfilePolicyTest.php` | covered |
| CoachProfilePolicy: the employing trainer only | BR-006, NFR-010 | `tests/Feature/Authorization/CoachProfilePolicyTest.php` | covered — pins the organisation boundary Slice B replaces with TrainerContext |
| A 7-day rolling session is the repository default | Q-01.07 | `tests/Feature/Auth/AccountStatusTest.php::test_the_repository_defaults_to_a_seven_day_rolling_session` | partial — the committed default is asserted; the effective value comes from the environment |
| Login attempts are rate limited | NFR-007 | `tests/Feature/Auth/LoginTest.php::test_repeated_attempts_are_throttled` | covered |
| Password reset requests are rate limited | NFR-007 | `tests/Feature/Auth/PasswordResetThrottleTest.php` | covered — Fortify ships a limiter for login only; reset had none |
| Owner and tenancy columns are not mass-assignable | NFR-010, NFR-011 | `tests/Feature/Authorization/MassAssignmentTest.php::test_profile_owner_columns_cannot_be_mass_assigned`, `::test_an_audit_row_cannot_be_mass_assigned_at_all` | covered |
| State-changing requests are CSRF protected | NFR-008 | `tests/Feature/CsrfProtectionTest.php` | partial — no exemptions and every posting form emits a token; an HTTP-level assertion is impossible because ValidateCsrfToken short-circuits under `runningUnitTests()` |
| Token TTLs: verification 24 h, reset 1 h | NFR-009 | `tests/Feature/Auth/EmailVerificationTest.php::test_the_verification_expiry_is_configured_for_24_hours`, `::test_a_generated_verification_link_expires_24_hours_out` | partial — verification's 24 h TTL is now asserted at both the config and the generated-signature level; the reset link's 1 h TTL is still untested |
| The directory stays paginated at scale | NFR-002 | `tests/Feature/Admin/UsersDirectoryTest.php::test_the_listing_is_paginated`, `::test_rendering_the_directory_issues_a_bounded_number_of_queries` | partial — page size and a bounded query count (via `DB::listen()` over 300 seeded users) are asserted; the 10k-row wall-clock target is not, and a timing assertion would be flaky |
| Sensitive operations are audited with both identities | NFR-011 | `tests/Feature/Admin/CreateTrainerAccountTest.php::test_the_creation_is_audited_with_the_acting_admin` | partial — trainer creation only; impersonation and deletion are Slice D |
| Auth events are audited (login, logout, failure, throttle, forced logout, denial) | NFR-011, A09 | `tests/Feature/Auth/AuthAuditTest.php` | covered — the attempted address is recorded, the submitted password never is |
| WCAG 2.1 AA | NFR-012 | — | uncovered — needs the real markup, which is the frontend work |

## Notes

- The suite runs against **MariaDB**, not SQLite (AD-013), so migrations are exercised on the
  engine the application actually uses.
- `Tests\TestCase` applies `RefreshDatabase` globally; every test builds the records it
  authenticates as or mutates, so no test depends on seeded fixtures or on execution order.
- `tests/Unit/ChildAbilitiesTest.php` and `tests/Unit/RoleTest.php` extend PHPUnit's own
  `TestCase` rather than the application one — they touch no database and should not pay for a
  migration.
