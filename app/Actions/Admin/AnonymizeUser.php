<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Profile\StoreProfilePhoto;
use App\Enums\UserStatus;
use App\Exceptions\UserLifecycleException;
use App\Models\PlayerProfile;
use App\Models\User;
use App\Models\UserDeletionLog;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * FR-018 / brainstorming Decision 7. Irreversible PII anonymization, never a hard delete:
 * `TrainerPlayer` rows, attendance, RSVPs and payment history all survive, rendering as
 * "Deleted User" (BR-018).
 *
 * The field mapping and the write order are both load-bearing and are restated here rather than
 * left implicit:
 *
 *  1. Capture the original email *before* anything is overwritten — a read-after-scrub would hash
 *     `deleted_{id}@deleted.invalid` instead of the address that was actually erased, and the
 *     password-reset-token cleanup below needs the same original address.
 *  2. Write the `UserDeletionLog` row *first*, inside the same transaction as the scrub, so a
 *     mid-failure can never lose the compliance record.
 *  3. Scrub the `User` row.
 *  4. Remove the stored photo via `StoreProfilePhoto` — reused, not reimplemented.
 *  5. Clear sessions and password-reset tokens for the original address.
 *  6. Anonymize the target's own self profile (if any) and every child it is the **sole** active
 *     guardian of (Gap 6) — a child two people still guard is left untouched, since anonymizing it
 *     would destroy data that does not belong to the deleted account.
 */
final class AnonymizeUser
{
    public function __construct(
        protected AuditLogger $auditLogger,
        protected StoreProfilePhoto $storeProfilePhoto,
    ) {}

    public function handle(User $target, User $actor, ?string $reason = null): void
    {
        if ($target->status === UserStatus::Deleted) {
            throw UserLifecycleException::alreadyDeleted($target);
        }

        $originalEmail = $target->email;

        DB::transaction(function () use ($target, $actor, $reason, $originalEmail): void {
            (new UserDeletionLog)->forceFill([
                'original_user_id' => $target->id,
                'email_hash' => UserDeletionLog::hashEmail($originalEmail),
                'deleted_by_user_id' => $actor->id,
                'reason' => $reason,
                'deleted_at' => now(),
            ])->save();

            $target->forceFill([
                'first_name' => 'Deleted',
                'last_name' => 'User',
                // .invalid is RFC 2606-reserved and guaranteed never to resolve — unlike
                // example.com, which is also reserved but is a live domain operated by IANA.
                'email' => "deleted_{$target->id}@deleted.invalid",
                // Never null — a null password would fail open on some auth paths; a fresh,
                // unknowable hash fails closed instead.
                'password' => Hash::make(Str::random(40)),
                'phone' => null,
                'remember_token' => null,
                'status' => UserStatus::Deleted,
            ])->save();

            $this->storeProfilePhoto->remove($target);

            DB::table('sessions')->where('user_id', $target->id)->delete();
            DB::table('password_reset_tokens')->where('email', $originalEmail)->delete();

            $this->anonymizeGuardedProfiles($target);
        });

        $this->auditLogger->log('user.anonymized', $target, ['reason' => $reason]);
    }

    protected function anonymizeGuardedProfiles(User $target): void
    {
        $profile = $target->playerProfile;

        if ($profile instanceof PlayerProfile) {
            $this->anonymizeProfile($profile);
        }

        // Gap 6: only where the deleted user is the child's *sole* active guardian. A child two
        // people still legitimately guard keeps its data intact.
        $target->guardedPlayerProfiles()
            ->get()
            ->each(function (PlayerProfile $child): void {
                if ($child->guardians()->count() === 1) {
                    $this->anonymizeProfile($child);
                }
            });
    }

    protected function anonymizeProfile(PlayerProfile $profile): void
    {
        $profile->forceFill([
            'name' => 'Deleted User',
            'birth_date' => null,
            'school' => null,
            'jersey_number' => null,
            'emergency_contact' => null,
        ])->save();

        $this->storeProfilePhoto->remove($profile, withThumbnail: false);
    }
}
