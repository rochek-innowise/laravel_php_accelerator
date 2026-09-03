<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Profile\StoreProfilePhoto;
use App\Enums\UserStatus;
use App\Exceptions\UserLifecycleException;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
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
 *  4. Remove the stored photo via `StoreProfilePhoto` — reused, not reimplemented. The actual disk
 *     delete is deferred by that action to `DB::afterCommit()` (Gap 3): a filesystem delete can't be
 *     rolled back, so it must never happen before the DB write it depends on is durable.
 *  5. Clear sessions and password-reset tokens for the original address.
 *  6. Anonymize the target's own self profile (if any) and every child it is the **sole** active
 *     guardian of (Gap 6) — a child two people still guard is left untouched, since anonymizing it
 *     would destroy data that does not belong to the deleted account.
 *  7. Scrub the target's own coach/trainer identity, if any (Gap 2): free-text/identifying columns
 *     only — `TrainerPlayer`, attendance and payment history read nothing else off these rows.
 *  8. Purge notification rows naming the target or any profile just anonymized (Gap 1): these are
 *     transient UI state, not the compliance trail, so deleting is simpler and safer than trying to
 *     surgically edit a `data` JSON blob column-by-column.
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

            $anonymizedProfileIds = $this->anonymizeGuardedProfiles($target);

            $this->anonymizeOwnCoachAndTrainerIdentity($target);

            $this->purgeNotifications($target, $anonymizedProfileIds);
        });

        $this->auditLogger->log('user.anonymized', $target, ['reason' => $reason]);
    }

    /** @return list<int> the ids of every PlayerProfile just anonymized, for the notification purge below. */
    protected function anonymizeGuardedProfiles(User $target): array
    {
        $anonymizedProfileIds = [];

        $profile = $target->playerProfile;

        if ($profile instanceof PlayerProfile) {
            $this->anonymizeProfile($profile);
            $anonymizedProfileIds[] = $profile->getKey();
        }

        // Gap 6: only where the deleted user is the child's *sole* active guardian. A child two
        // people still legitimately guard keeps its data intact.
        $target->guardedPlayerProfiles()
            ->get()
            ->each(function (PlayerProfile $child) use (&$anonymizedProfileIds): void {
                if ($child->guardians()->count() === 1) {
                    $this->anonymizeProfile($child);
                    $anonymizedProfileIds[] = $child->getKey();
                }
            });

        return $anonymizedProfileIds;
    }

    protected function anonymizeProfile(PlayerProfile $profile): void
    {
        $profile->forceFill([
            'name' => 'Deleted User',
            'birth_date' => null,
            'gender' => null,
            'school' => null,
            'jersey_number' => null,
            'emergency_contact' => null,
        ])->save();

        $this->storeProfilePhoto->remove($profile, withThumbnail: false);
    }

    /**
     * Gap 2. Only the target's *own* coach/trainer row, if any — never every coach/trainer the
     * platform knows about. Free-text/identifying columns only:
     *
     *  - `CoachProfile`: `bio`, `credentials`, `certifications` — routinely self-identifying free
     *    text, and `is_public` may render it to strangers. `status`, `joined_at` and
     *    `trainer_profile_id` are left alone — attendance and roster history read those.
     *  - `TrainerProfile`: this *is* the tenant root a whole organisation's `TrainerPlayer`/
     *    attendance/payment history hangs off, so the row itself must survive — but its
     *    `business_name`/`slug`/`address`/`website`/`description` are the same category of data as
     *    the `User` row's own name (unambiguously so for a sole trader), and get the identical
     *    "Deleted ..." treatment rather than a silent carve-out. `logo_path` and `primary_color`
     *    are left alone: they were not named by the finding and are visual branding, not
     *    identifying text.
     */
    protected function anonymizeOwnCoachAndTrainerIdentity(User $target): void
    {
        $coach = $target->coachProfile;

        if ($coach instanceof CoachProfile) {
            $coach->forceFill([
                'bio' => null,
                'credentials' => null,
                'certifications' => null,
            ])->save();
        }

        $trainer = $target->trainerProfile;

        if ($trainer instanceof TrainerProfile) {
            $trainer->forceFill([
                'business_name' => 'Deleted Organisation',
                'slug' => 'deleted-organisation-'.$trainer->getKey(),
                'address' => null,
                'website' => null,
                'description' => null,
            ])->save();
        }
    }

    /**
     * Gap 1. `notifications.data` is a JSON blob written by the notification classes themselves
     * (`PurchaseApprovalRequested`, `PurchaseApprovalExpired`, `PurchaseApprovalBypassed`,
     * `ChildShareLinkBlocked`), every one of which persists a plaintext `child_name`. Two distinct
     * leaks, both closed by deleting rather than editing:
     *
     *  - Rows addressed *to* the target (`notifiable_type`/`notifiable_id`) — the target's own
     *    notification history, which may itself name other people.
     *  - Rows addressed to *other* guardians that reference a profile just anonymized above
     *    (`data->player_profile_id`) — a co-guardian's bell still pointing at the child's real name
     *    after this call scrubbed it from `player_profiles` itself.
     *
     * @param  list<int>  $anonymizedProfileIds
     */
    protected function purgeNotifications(User $target, array $anonymizedProfileIds): void
    {
        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $target->getKey())
            ->delete();

        if ($anonymizedProfileIds !== []) {
            DB::table('notifications')
                ->whereIn('data->player_profile_id', $anonymizedProfileIds)
                ->delete();
        }
    }
}
