---
{
  "id": "MEM-20260903-9b3c4d72",
  "title": "Use .invalid domain over example.com for anonymized emails—RFC 2606 reservation",
  "type": "constraint",
  "status": "active",
  "scope": ["application"],
  "tags": ["gdpr", "email", "dns", "compliance", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["app/Actions/Admin/AnonymizeUser.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# Use .invalid Domain Over example.com for Anonymized Emails

## Durable Context

When generating a placeholder email for a GDPR-anonymized user, use the `.invalid` TLD instead of `example.com`.

**Why:** `.invalid` is RFC 2606-reserved and is **guaranteed never to resolve**. `example.com` is also RFC 2606-reserved but is a live IANA-operated domain; mail routed to it could theoretically be delivered or logged, creating a privacy leak.

Format: `deleted_{user_id}@deleted.invalid`

## Consequences

- Replace any `example.com` placeholder emails with `.invalid` domain.
- The `.invalid` TLD is meant for this exact use case: a domain that can never be resolved or deliver mail.
- This also applies to any test email that should never reach a real inbox (use `.test` or `.invalid`, not `example.com`).
- RFC 2606 also reserves `.test`, `.example`, and `.localhost` for testing; `.invalid` is the one for invalid/disabled addresses.

## Verification

From `app/Actions/Admin/AnonymizeUser.php`:

```php
$target->forceFill([
    'first_name' => 'Deleted',
    'last_name' => 'User',
    // .invalid is RFC 2606-reserved and guaranteed never to resolve — unlike
    // example.com, which is also reserved but is a live domain operated by IANA.
    'email' => "deleted_{$target->id}@deleted.invalid",
    // ...
])->save();
```

The comment explains the distinction and the choice. Test coverage verifies:
- The email ends with `.invalid`
- The email is different for each deleted user (includes the user ID)
- Password-reset tokens for the original email are cleared, not the anonymized one
