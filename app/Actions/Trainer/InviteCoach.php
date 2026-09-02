<?php

declare(strict_types=1);

namespace App\Actions\Trainer;

use App\Enums\CoachStatus;
use App\Enums\ShareLinkType;
use App\Models\CoachProfile;
use App\Models\ShareLink;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Notifications\CoachInvitation;
use App\Services\AuditLogger;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FR-013 / BR-009: a single-use link expiring in seven days.
 *
 * The pre-check here is a courtesy — it turns "this coach already works elsewhere" into a field
 * error at invite time instead of a dead end at accept time. It is *not* the enforcement: BR-006
 * is enforced by the generated-column unique index, because a check made now says nothing about
 * the state seven days from now when the link is actually followed.
 */
final class InviteCoach
{
    public function __construct(protected AuditLogger $auditLogger, protected TrainerContext $context) {}

    /**
     * @throws ValidationException
     */
    public function handle(TrainerProfile $trainer, User $actor, string $email, ?string $note = null): ShareLink
    {
        // Stored lowercased: MySQL's unique index on users.email is case-insensitive, so the rest
        // of the system already treats two spellings as one account. Without this, an invitation
        // to Coach@Example.test can never be accepted by coach@example.test.
        $email = Str::lower(trim($email));

        $this->guardAgainstAnActiveElsewhere($email, $trainer);

        $link = DB::transaction(function () use ($trainer, $actor, $email): ShareLink {
            $link = new ShareLink(['type' => ShareLinkType::Coach]);

            $link->forceFill([
                'code' => ShareLink::mintCode(),
                'trainer_profile_id' => $trainer->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'target_email' => $email,
                'max_uses' => ShareLinkType::Coach->maxUses(),
                'expires_at' => now()->addDays(ShareLinkType::Coach->ttlInDays() ?? 7),
                'is_active' => true,
                'uses_count' => 0,
            ])->save();

            $this->auditLogger->log('coach.invited', $link, [
                'trainer_profile_id' => $trainer->getKey(),
                'target_email' => $email,
            ]);

            return $link;
        });

        // After commit (AD-007): a rolled-back invitation must never leave a live link in someone's
        // inbox.
        DB::afterCommit(function () use ($link, $trainer, $email, $note): void {
            Notification::route('mail', $email)->notify(new CoachInvitation($link, $trainer, $note));
        });

        return $link;
    }

    /**
     * Re-issuing to the same address supersedes the previous link rather than stacking codes.
     *
     * Order matters: the replacement is minted *first*. Retiring the old link up front meant that
     * a `handle()` which then threw — the invitee became active elsewhere in the meantime, say —
     * destroyed the trainer's only pending invitation and issued nothing in its place.
     *
     * @throws ValidationException
     */
    public function resend(ShareLink $link, TrainerProfile $trainer, User $actor, ?string $note = null): ShareLink
    {
        $replacement = $this->handle($trainer, $actor, (string) $link->target_email, $note);

        $link->forceFill(['is_active' => false])->save();

        return $replacement;
    }

    /**
     * @throws ValidationException
     */
    protected function guardAgainstAnActiveElsewhere(string $email, ?TrainerProfile $inviting = null): void
    {
        $existing = User::whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();

        if ($existing === null) {
            return;
        }

        $active = $this->context->runAsSystem(
            fn (): ?CoachProfile => CoachProfile::query()
                ->where('user_id', $existing->getKey())
                ->where('status', CoachStatus::Active)
                ->first()
        );

        if ($active === null) {
            return;
        }

        // "Another" is wrong when the coach already works here, and the fix is different too:
        // there is nothing to release, the invitation is simply redundant.
        throw ValidationException::withMessages([
            'email' => $active->trainer_profile_id === $inviting?->getKey()
                ? 'This coach is already active in your organisation.'
                : 'This coach is already active under another organisation. They must be released first.',
        ]);
    }
}
