---
{
  "id": "MEM-20260903-c4e81b7a",
  "title": "A palette token named for a surface must never be used as a text colour",
  "type": "convention",
  "status": "active",
  "scope": ["frontend"],
  "tags": ["css", "tailwind", "design-tokens", "accessibility", "wcag", "nfr-012"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["resources/css/app.css", "resources/views/components/impersonation-banner.blade.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# A Surface Token Used as a Text Colour Renders Invisible Text

## Durable Context

This palette splits tokens by role: `field` (#EEF0EA) is the **page background**, `paper`
(#FFFFFF) is the **card surface**, `ink`/`ink-soft` are **text**, and `court` is the **brand**.
`.btn-ghost` was written as `text-field` + `border-field/30` on `bg-transparent`. Inside a
`bg-paper` card that is #EEF0EA text on #FFFFFF — a contrast ratio of roughly **1.09:1**, where
WCAG AA (NFR-012) requires 4.5:1 for text and 3:1 for a UI component boundary. The labels read as
empty gaps; the buttons looked "transparent".

Two neighbouring surface tokens are the trap: `field` and `paper` differ by so little that using
one as a foreground on the other is invisible, while the code still *looks* deliberate because it
names a real design token rather than a raw hex value.

## Consequences

- Foreground colours come from the text or brand tokens (`ink`, `ink-soft`, `court`, `foul`),
  never from `field` or `paper`. A surface token is legitimate as a foreground only when it sits
  on a genuinely contrasting ground — `text-paper` on `bg-court` or `bg-foul`, for instance.
- A component class that assumes a coloured ground must not be the shared base for components used
  on light cards. The tell that this had already gone wrong: the impersonation banner used
  `.btn-ghost` but overrode both colours inline (`border-paper/60 text-paper`) for its dark-red
  ground — a per-call-site override of a base class's colours means the base is wrong for the
  common case.
- Tailwind utilities outrank the `@layer components` layer, so an inline override at one call site
  keeps working after the base class is corrected. Fix the base rather than adding more overrides.
- Review check: grep new component classes for `text-field`, `text-paper`, `border-field` and
  confirm the ground each is actually rendered on.
- Automated tests do not catch this — the full suite stayed green through the entire regression.
  Contrast is caught by eye, by a contrast checker, or by an accessibility audit, so a visual pass
  belongs in the definition of done for any styling change. Related: [[MEM-20260903-1e8f5d2a]].

## Verification

Corrected definition in `resources/css/app.css`:

```css
.btn-ghost {
    @apply ... border border-court/50 bg-transparent ... text-court ... hover:bg-court/10 hover:border-court;
}
```

`court` (#1D4E89) on `paper` (#FFFFFF) measures **8.3:1**, comfortably past AA. The regression had
reached 13 call sites across nine Blade files — the family Add/Remove controls, the approvals Deny
button, the availability Reset control and three trainer screens — so a single wrong token in one
component class degraded most of the application's secondary actions at once.
