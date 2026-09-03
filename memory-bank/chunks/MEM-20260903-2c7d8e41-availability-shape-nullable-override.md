---
{
  "id": "MEM-20260903-2c7d8e41",
  "title": "Availability shape: nullable trainer_profile_id with no global scope",
  "type": "architecture",
  "status": "active",
  "scope": ["application"],
  "tags": ["availability", "data-model", "tenancy", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["app/Models/Availability.php", "database/migrations"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# Availability Shape: Nullable trainer_profile_id with No Global Scope

## Durable Context

The `Availability` model implements a **default/override pattern**, not a global scope hierarchy:

- `trainer_profile_id = NULL` → This is the default availability set, shared across all trainers.
- `trainer_profile_id = <id>` → This row overrides the default for one specific trainer.

This design **deliberately avoids a `BelongsToTenant` global scope** on the Availability model, because:
1. A NULL value means "use the default," which must be visible in queries.
2. A global scope would hide NULL rows, breaking the entire default-fallback logic.
3. Isolation is achieved through joining via `available_for` and `TrainerPlayer` (which is already scoped to the tenant).

## Consequences

- When querying availabilities, remember that NULL `trainer_profile_id` rows exist and are semantically significant.
- The `AvailabilityResolver` service handles the default/override resolution; use it instead of querying Availability directly.
- Do not add a `BelongsToTenant` scope to Availability; it would break the design.
- Code review: if a query on Availability forgets to check for NULL `trainer_profile_id`, it's either:
  - Filtering to a specific trainer (wants non-NULL), or
  - Fetching defaults (wants NULL), or
  - Needs both, with deliberate union or case logic.

## Verification

From `app/Models/Availability.php`:

```php
/** The `PlayerProfile` or `CoachProfile` this row belongs to. */
/** @return MorphTo<Model, $this> */
public function availableFor(): MorphTo
{
    return $this->morphTo();
}

/** Null for a default row; the one trainer this override applies to otherwise. */
/** @return BelongsTo<TrainerProfile, $this> */
public function trainerProfile(): BelongsTo
{
    return $this->belongsTo(TrainerProfile::class);
}

// No #[Fillable] and no BelongsToTenant, never a global scope. `trainer_profile_id` NULL means
// "the default set, apply everywhere"; non-null means "override for this trainer only".
```

The database schema includes:
- `trainer_profile_id` nullable foreign key to `trainer_profiles`
- No automatic scope that hides NULL rows
- Composite indexes supporting queries like "availability for subject X with trainer Y" and "default availability for subject X"

The `AvailabilityResolver` service:
- Queries the default (NULL) rows once
- Queries trainer-specific overrides (non-NULL rows) for each trainer
- Returns the override if present, otherwise the default
