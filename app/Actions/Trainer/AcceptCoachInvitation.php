<?php

declare(strict_types=1);

namespace App\Actions\Trainer;

use App\Actions\ShareLink\RedeemShareLink;
use App\Enums\CoachStatus;
use App\Enums\Role;
use App\Enums\ShareLinkType;
use App\Models\CoachProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FR-013 / BR-006. The coach becomes active under exactly one organisation.
 *
 * Two guards, doing different jobs. `lockForUpdate` on this user's coach rows serialises two
 * simultaneous acceptances so the loser sees a clean field error. The generated-column unique
 * index is what makes the rule *true* — it holds even against a write that never passed through
 * this action at all.
 */
final class AcceptCoachInvitation
{
    public function __construct(
        protected RedeemShareLink $redeem,
        protected AuditLogger $auditLogger,
        protected TrainerContext $context,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(string $code, User $user): CoachProfile
    {
        return DB::transaction(function () use ($code, $user): CoachProfile {
            $link = $this->redeem->lockRedeemable($code, ShareLinkType::Coach);
            $trainer = $link->trainerProfile()->firstOrFail();

            // The invitation names an address; honouring it stops a forwarded link from enrolling
            // whoever happens to open it.
            if ($link->target_email !== null
                && ! hash_equals(Str::lower($link->target_email), Str::lower($user->email))) {
                throw ValidationException::withMessages([
                    'code' => 'This invitation was issued to a different email address.',
                ]);
            }

            $this->guardAgainstAnUnverifiedAddress($user);
            $this->guardAgainstAnIneligibleRole($user);
            $this->guardAgainstAnActiveElsewhere($user);

            $profile = $this->context->runFor($trainer, function () use ($user, $trainer): CoachProfile {
                $existing = CoachProfile::query()->where('user_id', $user->getKey())->first();

                $profile = $existing ?? new CoachProfile;

                $profile->forceFill([
                    'user_id' => $user->getKey(),
                    'trainer_profile_id' => $trainer->getKey(),
                    'status' => CoachStatus::Active,
                    'joined_at' => now(),
                ]);

                try {
                    $profile->save();
                } catch (QueryException $e) {
                    // Only a unique-constraint violation means BR-006 refused the row. A deadlock,
                    // a lock-wait timeout or a dropped connection must stay an exception: this
                    // method holds two row locks, so those are all plausible here, and dressing
                    // one up as a field error would hide a real fault and skip the retry.
                    if (! $this->isUniqueViolation($e)) {
                        throw $e;
                    }

                    // The generated-column unique index doing its job. The driver message is not
                    // forwarded: it names the index and the other organisation's row.
                    throw ValidationException::withMessages([
                        'code' => 'You are already active under another organisation.',
                    ]);
                }

                return $profile;
            });

            // A Player account that accepts becomes a Coach; privilege columns are never
            // mass-assignable, so the change is deliberate and audited. Which accounts may reach
            // this point at all is decided by guardAgainstAnIneligibleRole() above.
            if ($user->role !== Role::Coach) {
                $user->forceFill(['role' => Role::Coach])->save();
            }

            $this->redeem->consume($link);

            $this->auditLogger->log('coach.invitation-accepted', $profile, [
                'trainer_profile_id' => $trainer->getKey(),
                'share_link_id' => $link->getKey(),
            ]);

            return $profile;
        });
    }

    /**
     * The `target_email` check is only worth anything if the address has been proven.
     *
     * Without this, the comparison is against a column the redeemer typed in themselves moments
     * earlier, so anyone with a leaked code and the invitee's address takes the BR-006 slot and
     * spends the single-use link — leaving the real coach unable to accept and un-reinvitable
     * until a trainer releases the impostor.
     *
     * @throws ValidationException
     */
    protected function guardAgainstAnUnverifiedAddress(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        throw ValidationException::withMessages([
            'code' => 'Confirm your email address first, then open this invitation again.',
        ]);
    }

    /** SQLSTATE 23000 with MySQL error 1062 is the duplicate-key case, and only that. */
    protected function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000' && ($e->errorInfo[1] ?? null) === 1062;
    }

    /**
     * Only a Player or an existing Coach may accept.
     *
     * Without this the acceptance below rewrites whatever role the redeemer held: a Super Admin
     * who follows a forwarded coach link loses `isSuperAdmin()`, the admin routes and the
     * `Gate::before` bypass, and a Trainer is demoted while their `TrainerProfile` row survives —
     * an organisation with no reachable owner. Neither has a path back in the codebase, since the
     * only other writes to `role` are account creation and trainer creation.
     *
     * @throws ValidationException
     */
    protected function guardAgainstAnIneligibleRole(User $user): void
    {
        if (in_array($user->role, [Role::Player, Role::Coach], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'code' => 'This account cannot join a coaching staff. Ask the trainer to invite a different address.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    protected function guardAgainstAnActiveElsewhere(User $user): void
    {
        $active = $this->context->runAsSystem(
            fn (): ?CoachProfile => CoachProfile::query()
                ->where('user_id', $user->getKey())
                ->where('status', CoachStatus::Active)
                ->lockForUpdate()
                ->first()
        );

        if ($active !== null) {
            throw ValidationException::withMessages([
                'code' => 'You are already active under another organisation.',
            ]);
        }
    }
}
