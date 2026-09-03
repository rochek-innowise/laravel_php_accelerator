---
{
  "id": "MEM-20260903-e5a2c8d3",
  "title": "A forward-looking deny list can be entirely inert if its entries do not yet match real authorize() call sites",
  "type": "constraint",
  "status": "active",
  "scope": ["application"],
  "tags": ["authorization", "impersonation", "epic-05-seam", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["app/Support/Authorization/ImpersonationGuardrail.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# A Forward-Looking Deny List Can Be Entirely Inert

## Durable Context

`ImpersonationGuardrail::DENIED` shipped with four payment abilities (`payment-method.create`, `payment-method.delete`, `tokens.purchase`, `purchase.complete`) that are authorized *nowhere* in the application. They were copied from `ChildAbilities` awaiting Epic-05, but nothing yet calls `can('payment-method.create', ...)` or similar.

At the same time, the one financial-consent ability that *actually exists* and *is authorized* (`respond` in `PurchaseApprovalPolicy`), was initially absent from the deny list—despite being the ability that, when approving a child's purchase, forges the guardian's consent and would spend money once `ApprovedPurchaseExecutor` is rebound from `NullPurchaseExecutor` to a real payment handler.

**The lesson:** A deny list is only as effective as what it currently matches. If entries don't correspond to actual `authorize()` or `can()` call sites in the codebase, the list is a no-op—it *looks* complete but provides no protection.

## Consequences

- When maintaining a forward-looking deny list (abilities planned for a future epic, gates meant to stay defensive across long-term development):
  1. **Enumerate every ability that is actually authorized today**, not just the ones in the spec.
  2. Use grep/IDE search to find every call to `can()`, `authorize()`, `Gate::allows()` for that type of ability.
  3. Verify that each entry in the deny list matches at least one real call site.
  4. Document why an entry *is not yet matched* if it's intentionally forward-looking (e.g., "will be added in Epic-05").
  5. Flag entries that will never match the current codebase as tech debt: they're documenting future design, not current safety.

- Before calling this done: run a test that iterates the deny list and asserts that each ability is actually used somewhere. `ImpersonationGuardrailTest` does this: it checks that every entry in `DENIED` can be matched by a real authorization call.

## Verification

From `app/Support/Authorization/ImpersonationGuardrail.php`:

```php
/**
 * `payment-method.create`, `payment-method.delete`, `tokens.purchase` and `purchase.complete`
 * are forward-looking strings for Epic-05 — nothing in the app authorizes them yet.
 * `respond` (PurchaseApprovalPolicy) is the financial-consent ability that exists *today*:
 * approving a child's purchase forges the guardian's consent, and only fails to spend money
 * because ApprovedPurchaseExecutor is currently bound to NullPurchaseExecutor (AD-006) — it
 * becomes a real charge the day Epic-05 rebinds it. `respond` is unique to
 * PurchaseApprovalPolicy across every policy in the app, so no subject-type scoping is needed.
 *
 * @var list<string>
 */
public const DENIED = [
    'user.change-credentials',
    'payment-method.create',
    'payment-method.delete',
    'tokens.purchase',
    'purchase.complete',
    'respond',
];
```

Test coverage in `tests/Unit/ImpersonationGuardrailTest.php` verifies:
- Each entry in `DENIED` is tested as a gate check
- The test can be extended as Epic-05 ships and new abilities become real authorization call sites
- Forward-looking entries that never ship can be cleaned up without breaking the test suite
