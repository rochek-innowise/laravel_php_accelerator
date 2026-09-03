---
{
  "id": "MEM-20260903-d3c8b1f4",
  "title": "Gate ability names are not namespaced, so a bare ability in a bypass-suppression list affects every policy",
  "type": "constraint",
  "status": "active",
  "scope": ["application"],
  "tags": ["authorization", "gates", "security", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["app/Providers/AppServiceProvider.php", "app/Support/Authorization/ImpersonationGuardrail.php", "tests/Feature/Authorization/SuperAdminSelfLifecycleTest.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# Gate Ability Names Are Not Namespaced

## Durable Context

A bare ability string in `AppServiceProvider::NOT_BYPASSABLE` (which suppresses the Super Admin bypass for that ability) inadvertently affects *all* policies that define the same ability, not just the one you meant.

**Initial defect:** Adding `'delete'` to `NOT_BYPASSABLE` to prevent a Super Admin from GDPR-deleting their own account also suspended the bypass for `ShareLinkPolicy::delete` and `TrainerPlayerPolicy::delete`, preventing a Super Admin from deleting unrelated objects they should be able to delete.

**Underlying vulnerability:** The Super Admin `Gate::before()` bypass had only `'impersonate'` listed initially, so it returned `true` for `deactivate`, `reactivate`, and `delete` before `UserPolicy`'s own `! $user->is($subject)` self-guards ever ran—allowing a Super Admin to deactivate or GDPR-delete their own account and lock the platform out irreversibly.

## Consequences

- Always use **subject-type-aware checks** instead of bare ability strings when suppressing the bypass for a multi-policy ability.
- The pattern: check both the ability name and the subject's type/class before returning `null` (to let the policy run) vs `true`/`false` (to override).
- Example from the fix:

```php
Gate::before(function (User $user, string $ability, array $arguments = []): ?bool {
    if (in_array($ability, self::NOT_BYPASSABLE, true)) {
        return null;  // Let policy decide
    }

    // `delete` on a User subject is UserPolicy::delete's own self-guard
    if ($ability === 'delete' && ($arguments[0] ?? null) instanceof User) {
        return null;  // Let policy decide (it will refuse self)
    }

    // For all other abilities and subjects, Super Admin bypasses
    if ($user->isSuperAdmin() && ! $this->isImpersonating()) {
        return true;
    }

    return null;
});
```

- Mirror `ImpersonationGuardrail::denies()`'s idiom for consistency: one unified scoping pattern across the app.
- Code review: when a gate ability could be defined in multiple policies, check if the gate logic needs subject-type scoping.

## Verification

Found in `app/Providers/AppServiceProvider.php` at the `registerGates()` method, with explicit rationale:

```php
/**
 * `deactivate`, `reactivate` and `delete` were added as part of Slice D Track C: their own
 * `UserPolicy` methods already carry a `! $user->is($subject)` self-guard, but with only
 * `impersonate` listed here the Super Admin bypass above short-circuited every other ability
 * to `true` before that guard ever ran — so a Super Admin could deactivate or GDPR-delete
 * their *own* account and lock the platform's last admin out irreversibly. FR-017/FR-018 do
 * not intend this; the self-guards are only authoritative once their abilities are listed here.
 *
 * `delete` is deliberately *not* listed here (Slice D finding 5): ability names are not
 * namespaced, and `delete` is shared with `ShareLinkPolicy`/`TrainerPlayerPolicy`, both of
 * which hard-require `role === Role::Trainer` — listing the bare string would have suspended
 * the Super Admin bypass for every `delete` in the app, refusing a Super Admin those unrelated
 * deletes outright. `registerGates()` below scopes `delete` to a `User` subject instead,
 * mirroring `ImpersonationGuardrail::denies()`'s own identical scoping (and its comment naming
 * these same two policies) rather than introducing a second, differently-shaped idiom.
 */
protected const NOT_BYPASSABLE = ['impersonate', 'deactivate', 'reactivate'];
```

Test coverage in `tests/Feature/Authorization/SuperAdminSelfLifecycleTest.php` verifies:
- A Super Admin cannot deactivate/reactivate/delete themselves
- A Super Admin can still deactivate/reactivate/delete other users
- A Super Admin can still delete `ShareLink` and `TrainerPlayer` objects (proving the subject-type scoping works)
