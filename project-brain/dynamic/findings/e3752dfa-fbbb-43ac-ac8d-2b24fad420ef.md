---
{
  "authority": "verified",
  "authorized_owners": [
    "local"
  ],
  "confidence": 1.0,
  "conflicts": [],
  "created_at": "2026-09-02T22:16:04.183959+00:00",
  "external_id": "TASK-001-SLICE-C-F1",
  "files": [],
  "goal": "ChildForm::resolveSelectedTrainerIds() returned the submitted ids verbatim from a public Livewire property, and TrainerProfile is identity-class so findOrFail resolves any organisation. A multi-trainer guardian could enrol a child into an arbitrary organisation with no ShareLink, no invitation and no consent, and the organisation's business name rendered back on /family as an enumeration oracle. Breached NFR-010. Fixed by intersecting the submitted ids against the family's own trainers, the guard Overview::addTrainer already applied; pinned by two regression tests, one confirmed to fail when the fix is reverted.",
  "id": "e3752dfa-fbbb-43ac-ac8d-2b24fad420ef",
  "next_steps": [],
  "owner": "local",
  "privacy": "team",
  "progress": "",
  "revision": 2,
  "schema_version": 1,
  "source_fingerprints": [
    {
      "path": "app/Livewire/Family/ChildForm.php",
      "sha256": "5adeb9bc48732bd72f01ce9125f0918064ae37a6169c113e46cf0b8bd8124636"
    }
  ],
  "sources": [
    "app/Livewire/Family/ChildForm.php"
  ],
  "status": "resolved",
  "superseded_by": null,
  "supersedes": [],
  "title": "Forged trainer id on the child form wrote a cross-organisation roster row",
  "transitions": [
    {
      "actor": "local",
      "at": "2026-09-02T22:16:04.183959+00:00",
      "from": null,
      "reason": "Finding created",
      "to": "open"
    },
    {
      "actor": "local",
      "at": "2026-09-02T22:16:46.978925+00:00",
      "from": "open",
      "reason": "Fixed and pinned by test in Slice C; verified at 329 tests green",
      "to": "resolved"
    }
  ],
  "type": "finding",
  "updated_at": "2026-09-02T22:16:46.979152+00:00"
}
---

# Forged trainer id on the child form wrote a cross-organisation roster row

## Goal
ChildForm::resolveSelectedTrainerIds() returned the submitted ids verbatim from a public Livewire property, and TrainerProfile is identity-class so findOrFail resolves any organisation. A multi-trainer guardian could enrol a child into an arbitrary organisation with no ShareLink, no invitation and no consent, and the organisation's business name rendered back on /family as an enumeration oracle. Breached NFR-010. Fixed by intersecting the submitted ids against the family's own trainers, the guard Overview::addTrainer already applied; pinned by two regression tests, one confirmed to fail when the fix is reverted.

## Progress
Not started.

## Next Steps
- None

## Files
- None

## Sources
- `app/Livewire/Family/ChildForm.php`
