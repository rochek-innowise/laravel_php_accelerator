---
{
  "created": "2026-09-03",
  "id": "MEM-20260903-4dc71090",
  "last_verified": "2026-09-03",
  "review_after": "2027-09-03",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "app/Livewire/Trainer/Coaches.php",
      "sha256": "ec2b4bc5f703cb52b2d8a295c4d723b2b1a68c2bcc97729144350e368638c68d"
    },
    {
      "path": "project-brain/dynamic/findings/4dc71090-9df3-46c5-b52d-62a9c69b147e.md",
      "sha256": "14fe78571643bd1c4df7e4a51a2313932f39d003534010a81604d97b66a79209"
    }
  ],
  "sources": [
    "app/Livewire/Trainer/Coaches.php",
    "project-brain/dynamic/findings/4dc71090-9df3-46c5-b52d-62a9c69b147e.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "Coaches::resend() is type-blind, so a client-supplied id destroys the organisation's permanent player link",
  "type": "domain",
  "valid_from": "2026-09-03",
  "valid_to": null
}
---

# Coaches::resend() is type-blind, so a client-supplied id destroys the organisation's permanent player link

Coaches::resend() filters on ShareLinkType::Coach and authorizes update on the resolved row, so a client-supplied id can no longer revoke the organisation's permanent player link.

## Sources
- app/Livewire/Trainer/Coaches.php
