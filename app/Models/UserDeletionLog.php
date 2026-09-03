<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

// No #[Fillable] at all (mirrors AuditLog / ImpersonationLog) — written only through
// AnonymizeUser, via forceFill.
//
// Deliberately does NOT use SoftDeletes, despite the `deleted_at`-shaped column name: that column
// records when the *original user* was erased, not when this log row itself was soft-deleted.
// Mixing the trait in would silently hide every row from every query the moment it was added —
// the exact trap Decision 6 names for Auth::logout(), restated here for this column instead.
/**
 * @property int|null $original_user_id
 * @property string $email_hash
 * @property int|null $deleted_by_user_id
 * @property string|null $reason
 * @property Carbon $deleted_at
 */
class UserDeletionLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function originalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    /**
     * Salted, so the clear address is never stored — but comparable across rows, so "was this
     * address ever erased" / "is this person re-registering" can still be answered: hashing the
     * same address twice yields equal values. Lower-cased and trimmed first, so the same address
     * hashes identically regardless of how it was typed.
     *
     * Fails closed (throws) rather than hashing with an empty key when the salt is unconfigured —
     * an unsalted or empty-salt hash would be worse than storing nothing at all.
     */
    public static function hashEmail(string $email): string
    {
        $salt = config('gdpr.email_hash_salt');

        // Finding 8 (Slice D): empty() treats a salt of "0" as unconfigured (PHP's own
        // falsy-string quirk), which would fail this fail-closed check open for that one value.
        if ($salt === null || $salt === '') {
            throw new RuntimeException('GDPR_EMAIL_HASH_SALT is not configured.');
        }

        return hash_hmac('sha256', mb_strtolower(trim($email)), $salt);
    }
}
