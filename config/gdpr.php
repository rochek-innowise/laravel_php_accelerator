<?php

declare(strict_types=1);

// FR-018 / Slice D Decision 7. `email_hash_salt` has deliberately no default: an unconfigured
// salt must fail closed (UserDeletionLog::hashEmail() throws) rather than silently hash with an
// empty key, which would make every deployment's hashes trivially comparable to each other.
return [
    'email_hash_salt' => env('GDPR_EMAIL_HASH_SALT'),

    // Gap 10: the column and this config value exist from day one; the purge job itself is
    // deferred — no FR in this slice asks for it.
    'deletion_log_retention_years' => (int) env('GDPR_DELETION_LOG_RETENTION_YEARS', 6),
];
