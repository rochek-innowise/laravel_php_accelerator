---
{
  "id": "MEM-20260903-8f2e4a91",
  "title": "Blade escaping does not protect an HTML attribute in a JavaScript context",
  "type": "domain",
  "status": "active",
  "scope": ["application"],
  "tags": ["xss", "blade-templating", "security", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["app/Livewire/Admin/UsersTable.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# Blade Escaping Does Not Protect HTML Attributes in JavaScript Contexts

## Durable Context

A stored-XSS hole shipped with `{{ }}` escaping in an inline JavaScript handler:

```blade
onsubmit="return confirm('{{ $user->name }}')"
```

Blade escapes `'` to `&#039;`, but HTML parsers decode character references in attribute values *before* the JavaScript tokenizer runs. The decoded quote terminates the string, allowing an attacker to inject script. Any user able to set their own name—or any field interpolated into a JS handler—could break out and run code in the admin's origin.

The fix is not to use stronger escaping, but to stop putting the value in a JavaScript parsing context. Use `wire:confirm` (which Livewire reads via `getAttribute()` as plain data, never through a JS parser) or move off inline handlers entirely.

## Consequences

- Remove all inline event handlers (`onsubmit`, `onclick`, etc.) from templates. The codebase now has zero inline handlers — worth stating as the convention.
- Never interpolate user data into JavaScript parsing contexts, even with escaping.
- Use Livewire directives (`wire:click`, `wire:confirm`) or data attributes instead.
- Code review: flag any `on*="..."` with user input as a critical refactor.

## Verification

The fix is in `app/Livewire/Admin/UsersTable.php` at the `impersonate()` method:

```php
/**
 * Finding 1 (Slice D): replaces a raw `<form onsubmit="return confirm('...')">` whose
 * confirmation string interpolated `$user->name` directly into a JS-parsing HTML attribute —
 * `{{ }}`'s escaping decodes right back to a literal `'` there (an HTML attribute in a JS
 * context double-decodes character references before the JS tokenizer ever sees them), so any
 * user able to set their own name could break out of the string and run script in the Super
 * Admin's origin. `wire:click`/`wire:confirm` (used by the sibling deactivate/reactivate/
 * delete actions below) never puts the value through a JS parser at all — Livewire reads
 * `wire:confirm` via `getAttribute()` and passes it to `confirm()` as data.
 */
public function impersonate(User $user, StartImpersonation $action): void
{
    $this->authorize('impersonate', $user);
    $action->handle(request(), $this->actor(), $user);
    session()->flash('status', __('Now viewing as :name.', ['name' => $user->name]));
    $this->redirect(route('dashboard'), navigate: true);
}
```

The vulnerability was prevented by:
1. Replacing the form's `onsubmit` handler with `wire:click`
2. Using `wire:confirm` instead of inline JavaScript
3. Having Livewire read the attribute value as data, bypassing JS tokenization entirely

This class of vulnerability affects any inline handler + user data pattern; search the codebase for remaining `on*=` attributes as part of security audits.
