---
{
  "created": "2026-09-02",
  "id": "MEM-20260902-063160c0",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/063160c0-190c-48b6-b1e3-17deeb9d1c8f.md",
      "sha256": "ade2dcc08cca3948a403026d3749fedf7bfae0fc179687fecca715a468dc83c3"
    }
  ],
  "sources": [
    "project-brain/archive/finding/063160c0-190c-48b6-b1e3-17deeb9d1c8f.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "is_child_account invariant promised by the design was never asserted",
  "type": "domain",
  "valid_from": "2026-09-02",
  "valid_to": null
}
---

# is_child_account Invariant: Now Asserted in Both Seeding and Production

## The Invariant

A `User` with `is_child_account = true` must have exactly one backing `PlayerProfile` with 
`is_child = true`, and vice versa. The two columns are denormalized for authorization performance 
(avoiding joins on every request), so they must never disagree.

## Slice A/B: Seeding Only

Slices A and B exercised the invariant only over seeded test data. The assertion happened in 
`ChildAccountInvariantTest` against rows created by the seeder; no production code path wrote both 
sides together.

## Slice C: Exercised in Production

`CreateChildProfileAction` now creates the invariant in production when a guardian creates a child 
profile with its own login:

```php
// In one transaction:
$user = CreateNewUser::handle($email, $password);  // Creates User, initially is_child_account = false
$user->forceFill(['is_child_account' => true])->save();
$profile = PlayerProfile::create([...]);
$profile->forceFill(['is_child' => true, 'user_id' => $user->id])->save();
```

Both fields are written in the same transaction and tested at the action level in 
`CreateChildProfileTest::test_child_profile_with_login_asserts_the_invariant`. A profile-only child 
(no login) leaves `user_id` null and is not subject to the invariant.

## Consequences

The invariant is now exercised in production. Regression tests remain required; any code path 
creating either side must be audited. A future guardian-edit path or a policy change allowing 
child-account conversions would need the same careful treatment.
