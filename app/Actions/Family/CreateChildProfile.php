<?php

declare(strict_types=1);

namespace App\Actions\Family;

use App\Actions\Fortify\CreateNewUser;
use App\Exceptions\DuplicateChildProfileException;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FR-008. One transaction for the whole thing: `is_child` and `is_child_account` (when a login is
 * requested) are written together, so this is the first production write path for the invariant
 * `MEM-20260902-063160c0` flagged as asserted only over seeded data — `ChildAccountInvariantTest`
 * covers the seeder, a test alongside this action covers this path directly.
 */
final class CreateChildProfile
{
    public function __construct(
        private readonly CreateNewUser $createNewUser,
        private readonly AssociatePlayersWithTrainer $associate,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(User $actor, ChildProfileData $data): PlayerProfile
    {
        $this->assertAgeInRange($data->birthDate);

        if (! $data->confirmDuplicate) {
            $this->assertNoLikelyDuplicate($actor, $data);
        }

        // Checked before any write, like the two guards above: a rate-limited attempt should fail
        // the whole submission rather than roll back a profile the transaction already committed to.
        if ($data->wantsLogin) {
            $this->guardAgainstLoginCreationFlooding($actor);
        }

        return DB::transaction(function () use ($actor, $data): PlayerProfile {
            $profile = new PlayerProfile([
                'name' => $data->name,
                'birth_date' => $data->birthDate,
                'gender' => $data->gender,
                'school' => $data->school,
                'jersey_number' => $data->jerseyNumber,
                'emergency_contact' => $data->emergencyContact,
            ]);

            // Never accepted from the caller: this endpoint only ever creates a child. BR-022's
            // self profile is created at registration, so there is no "self" branch here to guard.
            $profile->forceFill(['is_child' => true]);
            $profile->save();

            $profile->guardians()->attach($actor->getKey(), ['is_primary' => true]);

            if ($data->wantsLogin) {
                $this->attachLogin($profile, $data);
            }

            // Decision 8: without this, the child just attached above is invisible to the
            // association loop right below it — trainableProfiles() memoizes per instance, and it
            // was already read (by this very method, indirectly, via the duplicate check on the
            // guardian's existing children) before this new row existed.
            $actor->resetTrainableProfilesCache();

            foreach ($data->trainerProfileIds as $trainerProfileId) {
                $trainer = TrainerProfile::query()->findOrFail($trainerProfileId);
                $this->associate->handle($trainer, $actor, [$profile->getKey()]);
            }

            $this->auditLogger->log('child-profile.created', $profile, [
                'guardian_user_id' => $actor->getKey(),
                'has_login' => $data->wantsLogin,
                'trainer_profile_ids' => $data->trainerProfileIds,
            ]);

            return $profile;
        });
    }

    /** Both flags land in the same transaction as the profile itself (see class docblock). */
    private function attachLogin(PlayerProfile $profile, ChildProfileData $data): void
    {
        [$firstName, $lastName] = $this->splitName($data->name);

        $child = $this->createNewUser->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => (string) $data->loginEmail,
            'password' => (string) $data->loginPassword,
            'password_confirmation' => (string) $data->loginPasswordConfirmation,
        ]);

        // Never mass-assigned (AD-016): `is_child_account` decides privilege, and
        // `email_verified_at` decides whether the `verified` middleware lets this login through.
        // Verified at creation, not left null pending a mail the child may have no independent way
        // to receive: the guardian's own already-verified account is what vouches for this
        // address, and FR-011 gives the child no path to verify it themselves (there is no
        // `Registered` event on this flow, so nothing would ever prompt it). Without this, a
        // freshly created child login could never pass `verified` and so could never reach
        // `/approvals` — unreachable in practice despite FR-011 requiring it.
        $child->forceFill([
            'is_child_account' => true,
            'email_verified_at' => now(),
        ])->save();

        // Never mass-assigned: a request-supplied user_id would let one account claim another
        // family's child (AD-016).
        $profile->forceFill(['user_id' => $child->getKey()])->save();
    }

    /**
     * A second account-creation surface next to `/join/{code}` (AD-004), reachable by any
     * authenticated guardian repeatedly submitting this form. Without a limit, it is unbounded
     * `Active` `User` creation on arbitrary addresses — which also turns `CreateNewUser`'s
     * `Rule::unique` failure into an email-existence oracle, and lets someone squat a third
     * party's address so it can never register through `/join/{code}` afterwards. Mirrors
     * `RedeemShareLink::guardAgainstRegistrationFlooding()`, with one addition: the acting
     * guardian's id joins the IP in the limiter key, not the IP alone, so a club's guardians behind
     * one NAT address don't share a single budget — one guardian spamming the form no longer locks
     * every other guardian on the same network out of it.
     *
     * The rejection is audited the same way `bootstrap/app.php`'s `ThrottleRequestsException`
     * handler audits a route-level throttle (`request.throttled`) — this limiter sits below that,
     * on Livewire's update endpoint, so nothing there ever sees it (`MEM-20260902-983c61d0`).
     *
     * @throws ValidationException
     */
    private function guardAgainstLoginCreationFlooding(User $actor): void
    {
        $ip = request()->ip();
        $key = 'family:child-login:'.$actor->getKey().':'.$ip;

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            $this->auditLogger->log('family.child-login-throttled', null, [
                'guardian_user_id' => $actor->getKey(),
                'ip' => $ip,
            ]);

            throw ValidationException::withMessages([
                'email' => 'Too many child logins created from here. Try again in a few minutes.',
            ]);
        }

        RateLimiter::hit($key, decaySeconds: 300);
    }

    /**
     * The child profile carries a single `name` field; Fortify's `CreateNewUser` wants first/last.
     * Split on the first space rather than collecting the login's name separately — one name for
     * one person, matching how `User::name()` recomposes first+last for display.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = explode(' ', trim($name), 2);

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    /**
     * @throws ValidationException
     */
    private function assertAgeInRange(string $birthDate): void
    {
        $age = Carbon::parse($birthDate)->age;
        $min = (int) config('training.child_age.min');
        $max = (int) config('training.child_age.max');

        if ($age < $min || $age > $max) {
            throw ValidationException::withMessages([
                'birth_date' => "A child profile must be between {$min} and {$max} years old.",
            ]);
        }
    }

    /**
     * Decision 2: scoped to the acting guardian's own family only — a global name search would
     * leak family membership across accounts. Dismissible, never a hard block.
     *
     * @throws DuplicateChildProfileException
     */
    private function assertNoLikelyDuplicate(User $actor, ChildProfileData $data): void
    {
        $normalizedName = $this->normalize($data->name);
        $birthYear = Carbon::parse($data->birthDate)->year;

        $duplicate = $actor->guardedPlayerProfiles()->get()->first(
            fn (PlayerProfile $existing): bool => $this->normalize($existing->name) === $normalizedName
                && $existing->birth_date?->year === $birthYear
        );

        if ($duplicate !== null) {
            throw DuplicateChildProfileException::forName($duplicate->name);
        }
    }

    private function normalize(string $name): string
    {
        return Str::of($name)->squish()->lower()->value();
    }
}
