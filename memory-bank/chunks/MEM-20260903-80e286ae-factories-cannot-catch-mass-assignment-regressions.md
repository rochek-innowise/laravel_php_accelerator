---
{
  "id": "MEM-20260903-80e286ae",
  "title": "Factories cannot catch mass-assignment regressions because they construct unguarded",
  "type": "convention",
  "status": "active",
  "scope": [
    "application"
  ],
  "tags": [
    "testing",
    "authorization",
    "factories",
    "mass-assignment",
    "slice-c"
  ],
  "created": "2026-09-02",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "sources": [
    "tests/Feature/Authorization/MassAssignmentTest.php",
    "app/Models/PlayerProfile.php",
    "app/Models/PurchaseApproval.php"
  ],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": [
    {
      "path": "app/Models/PlayerProfile.php",
      "sha256": "669536364daf073233045064747ad6036e44fffd7c0c346e892f2ea095e2ecee"
    },
    {
      "path": "app/Models/PurchaseApproval.php",
      "sha256": "f5838eb09bb91f0beb60b99557c1f6b4e866bf6d59db169beb55e1141a294459"
    },
    {
      "path": "tests/Feature/Authorization/MassAssignmentTest.php",
      "sha256": "ba76bc6f61293752d37a3a9ab959e8128177f5b8221e35558749bb798c12263e"
    }
  ]
}
---

# Factories Cannot Catch Mass-Assignment Regressions

## Durable Context

Eloquent factories construct models inside `Model::unguarded()`, a state where all columns are 
writable regardless of the model's `#[Fillable]` allow-list. This means a factory can populate 
privilege or ownership columns (`user_id`, `status`, `token_spend_requires_approval`) that are 
never intended to be mass-assignable — and the factory's green test proves nothing about whether 
those columns are actually guarded in production, where a request-supplied `update()` might 
reassign them.

## The Lesson

Every privilege or ownership column guarded by `#[Fillable]` needs an explicit test that attempts 
to mutate it via `update()` and asserts it was rejected. Factories pass because they bypass the 
guard; the test must not.

```php
public function test_player_profile_privilege_columns_are_refused_by_mass_assignment(): void
{
    $profile = PlayerProfile::factory()->child()->create([
        'token_spend_requires_approval' => true,
    ]);
    $original = $profile->token_spend_requires_approval;

    $profile->update(['token_spend_requires_approval' => false]);

    $this->assertTrue($profile->fresh()->token_spend_requires_approval);
}
```

The test asserts the actual database value *after* `update()`, not `getFillable()` — which would 
only restate the allow-list rather than prove mass-assignment is refused.

## Consequences

When adding a new privilege column, include a companion test in `MassAssignmentTest` that attempts 
to change it via `update()`. Factory tests passing does not mean the guard works.

## Verification

Commit ec1066d "Close the review findings on the family screen and approvals". Test file: 
`tests/Feature/Authorization/MassAssignmentTest.php` — specifically 
`test_player_profile_privilege_and_owner_columns_are_refused_by_mass_assignment` and 
`test_purchase_approval_refuses_mass_assignment_of_everything_but_parent_note`.
