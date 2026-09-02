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

        $child->forceFill(['is_child_account' => true])->save();

        // Never mass-assigned: a request-supplied user_id would let one account claim another
        // family's child (AD-016).
        $profile->forceFill(['user_id' => $child->getKey()])->save();
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
