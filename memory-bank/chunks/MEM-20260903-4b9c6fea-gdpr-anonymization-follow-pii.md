---
{
  "id": "MEM-20260903-4b9c6fea",
  "title": "GDPR anonymization has to follow the PII, not just the owning row",
  "type": "constraint",
  "status": "active",
  "scope": ["application"],
  "tags": ["gdpr", "privacy", "data-cleanup", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["app/Actions/Admin/AnonymizeUser.php", "tests/Feature/Admin/UserLifecycleTest.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# GDPR Anonymization Has to Follow the PII, Not Just the Owning Row

## Durable Context

When implementing a GDPR erasure, it is not enough to scrub the User row. Personally identifiable information can leak in:

- **Child entities:** A child's name can persist in a parent's PlayerProfile row.
- **JSON columns:** Field data survives in `notifications.data`, `metadata` columns, or other JSON storage; it is still rendered or accessible.
- **Free-text profile fields:** A coach's `bio`, `credentials`, and `certifications` can be self-identifying.
- **Trainer profile fields:** `address`, `description`.
- **Guardian relationships:** A child guarded by multiple people should only be anonymized if the deleted user is the *sole* remaining guardian.

**The lesson:** Before declaring an erasure complete, enumerate *every* table and JSON column that holds a copy of identifying data, including indirect references. A name that survives in a child's row or in a notification payload is just as much PII as a name in the User row.

## Consequences

- Use the `AnonymizeUser::handle()` method as a template; its structure is:
  1. Capture the original email before overwriting.
  2. Write the deletion log inside the transaction.
  3. Scrub the User row.
  4. Remove stored files (deferred to `DB::afterCommit()`).
  5. Clear sessions and password-reset tokens for the original email.
  6. Anonymize the target's own self profile and every child they are the **sole** active guardian of.
  7. Scrub the target's own coach/trainer identity (free-text fields only).
  8. Purge notification rows naming the target or any profile just anonymized.

- Scan the data model:
  - Which tables have name/address/email/phone fields?
  - Which tables have JSON columns that might hold names or user data?
  - Do any relationships carry identifying info transitively (a child's name through a coach's notification)?
  - Are there any cache tables or denormalized copies?

- Test every single one: write a test that verifies PII is scrubbed in each location.
- Example from the code:

```php
// Gap 1: `notifications.data` persists a plaintext `child_name` in four notification classes.
// Both leaks close: the target's own notification history, and any other guardian's
// notification that still names a child whose real name was just scrubbed elsewhere.
public function test_anonymizing_purges_notifications_naming_an_anonymized_child(): void
{
    $actor = User::factory()->superAdmin()->create();
    $target = User::factory()->create();
    $selfProfile = PlayerProfile::factory()->selfProfile($target)->create(['name' => 'Zinaida Petrenko']);

    $coGuardian1 = User::factory()->create();
    $this->insertNotification($coGuardian1, $selfProfile->id, 'Zinaida Petrenko');

    app(AnonymizeUser::class)->handle($target, $actor);

    $this->assertSame(0, DB::table('notifications')->where('data->player_profile_id', $selfProfile->id)->count());
}
```

## Verification

From `app/Actions/Admin/AnonymizeUser.php`, the full method documents gaps 1–6:

- **Gap 1:** Notifications naming profiles just anonymized
- **Gap 2:** Coach `bio`, `credentials`, `certifications`
- **Gap 3:** Filesystem photos deferred with `DB::afterCommit()`
- **Gap 4:** Self profile's six identifying fields (name, birth_date, gender, school, jersey_number, emergency_contact, photo)
- **Gap 5:** (Reserved for future payment history or other linked data)
- **Gap 6:** Solo-guarded children—do not anonymize co-guarded children, since they belong to other guardians

Test coverage in `tests/Feature/Admin/UserLifecycleTest.php` verifies each gap with its own test:
- `test_anonymizing_scrubs_the_targets_own_self_profile` (Gap 4)
- `test_anonymizing_scrubs_a_child_only_the_target_solely_guards` (Gap 6)
- `test_anonymizing_scrubs_the_targets_own_coach_identity` (Gap 2)
- `test_anonymizing_deletes_the_targets_own_notifications` (Gap 1)
- `test_anonymizing_purges_notifications_naming_an_anonymized_child_but_leaves_others_alone` (Gap 1, extended)
- `test_anonymizing_deletes_the_stored_photo_from_disk` (Gap 3)
