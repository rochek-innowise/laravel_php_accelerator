---
{
  "id": "MEM-20260903-b6d24f0c",
  "title": "Livewire\\Component already declares reset(), so a same-named component method is a fatal error",
  "type": "constraint",
  "status": "active",
  "scope": ["application"],
  "tags": ["livewire", "frontend", "naming", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["app/Livewire/Trainer/Branding.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# Livewire Components Cannot Define Their Own reset()

## Durable Context

`Livewire\Component` inherits `reset(...$properties)` from the `InteractsWithProperties` trait — it
resets public properties to their initial state. A component action named `reset()` with any other
signature is therefore an **override with an incompatible signature**, which PHP treats as a fatal
error, not a silent shadow. The trait origin does not soften this: PHP enforces signature
compatibility on trait-provided methods the same way it does on inherited ones.

The failure is at class-load time, so it surfaces as a hard 500 the first time the component is
resolved, not as a subtly wrong reset.

## Consequences

- Name the action for what it resets in domain terms — `resetBranding()`, `resetFilters()` — rather
  than the bare `reset()`. The domain-prefixed name is clearer at the call site anyway.
- The same caution applies to other `Livewire\Component` API surface a plausible action name could
  collide with: `mount`, `render`, `dispatch`, `redirect`, `validate`, `validateOnly`,
  `resetValidation`, `fill`, `skipRender`. Check the parent before naming a public method.
- A plain Action class has no such constraint — `UpdateTrainerBranding::reset()` is fine. It is
  legitimate for the component method and the action method it calls to differ in name for this
  reason; the divergence is deliberate and worth a comment where it occurs.

## Verification

From `app/Livewire/Trainer/Branding.php`, the docblock recording the constraint in place:

```php
/**
 * Named `resetBranding`, not `reset`: `Livewire\Component` already declares `reset(...$properties)`
 * (property reset, via the `InteractsWithProperties` trait) and PHP enforces signature
 * compatibility on an override even when the parent method comes from a trait — a same-named
 * method with a different signature is a fatal error, not a silent shadow.
 */
public function resetBranding(UpdateTrainerBranding $updateTrainerBranding): void
```

The component calls `$updateTrainerBranding->reset($trainer)` on the next line — the action keeps
the plain name because it is not a Livewire component. Related: [[MEM-20260903-2c7d8e41]].
