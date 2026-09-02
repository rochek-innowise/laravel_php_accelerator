---
{
  "id": "MEM-20260902-09f971b9",
  "title": "Unscoped identity-class models require guards against forged IDs on public request properties",
  "type": "domain",
  "status": "active",
  "scope": [
    "application"
  ],
  "tags": [
    "security",
    "authorization",
    "identity-class",
    "slice-c"
  ],
  "created": "2026-09-02",
  "last_verified": "2026-09-02",
  "review_after": "2027-09-02",
  "sources": [
    "app/Livewire/Family/ChildForm.php",
    "specs/architect-architecture.md"
  ],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-02",
  "valid_to": null,
  "source_digests": [
    {
      "path": "app/Livewire/Family/ChildForm.php",
      "sha256": "5adeb9bc48732bd72f01ce9125f0918064ae37a6169c113e46cf0b8bd8124636"
    },
    {
      "path": "specs/architect-architecture.md",
      "sha256": "9ae52c4452e70a97dcb786cd2e6d77977cf44c50cf335a6f32ed9af107a94a93"
    }
  ]
}
---

# Unscoped Identity-Class Models Require Guards Against Forged IDs On Public Request Properties

## Durable Context

IDs submitted on public Livewire properties are requests, not decisions — they arrive from untrusted 
client state and must be validated against the acting user's permitted set before use.

`TrainerProfile` is an identity-class model (like `User`): unscoped, no `BelongsToTenant` trait, and 
`findOrFail()` resolves any id in the database. When a Livewire form collected a multi-select of 
trainer ids in `$selectedTrainerIds` and passed them verbatim to `AssociatePlayersWithTrainer`, a 
parent could forge an arbitrary trainer id and enrol their child into an organisation they have no 
relationship with — a cross-tenant write violating NFR-010.

## The Fix

Submitted trainer ids must be intersected against the acting guardian's own trainable set before 
association:

```php
return array_values(array_intersect(
    array_map('intval', $this->selectedTrainerIds),
    $available->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
));
```

Forged ids drop inertly (they simply don't appear in the intersection); this guards against leaking 
whether a forged id even exists. The guard was already implemented identically in 
`Overview::addTrainer` for the picker on `/family` — it was simply not applied in the second code 
path, the form's own trainer selection.

## Consequences

Every Livewire public property that carries entity ids for mutation — form selections, pickers, 
multi-select lists — must have a guard re-deriving the permitted set from the actor's own scope 
before passing ids to an action. If the model is identity-class and unscoped, `findOrFail()` will 
happily resolve ids from any organisation; intersection is the minimum guard.

Review: look for other Livewire components or forms collecting model ids for assignment. Pinned in 
two regression tests: `tests/Feature/Family/TrainerAssociationSecurityTest`.

## Verification

Commit 8cec492 "Refuse a forged trainer id on the child form". Regression tests verify both that 
forged ids are silently dropped and that an association fails when all selected trainers are 
invalid. Schema and feature tests: `CreateChildProfileTest::test_a_child_is_associated_with_selected_trainers_only` 
and `TrainerAssociationSecurityTest`.
