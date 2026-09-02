<?php

declare(strict_types=1);

namespace Tests\Feature\Family;

use App\Actions\Family\AssociatePlayersWithTrainer;
use App\Actions\Family\ChildProfileData;
use App\Actions\Family\CreateChildProfile;
use App\Exceptions\DuplicateChildProfileException;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-008, and the first production write path for the `is_child`/`is_child_account` invariant
 * (`MEM-20260902-063160c0`) that was previously asserted only over seeded data.
 */
final class CreateChildProfileTest extends TestCase
{
    #[Test]
    public function a_child_profile_is_created_and_guarded_by_the_acting_parent(): void
    {
        $parent = User::factory()->create();

        $profile = app(CreateChildProfile::class)->handle($parent, $this->data(name: 'Alex Doe', gender: 'female'));

        $this->assertTrue($profile->fresh()->is_child);
        $this->assertSame('female', $profile->fresh()->gender);
        $this->assertTrue($profile->isGuardedBy($parent));
        $this->assertSame(1, $profile->guardians()->wherePivot('is_primary', true)->count());
    }

    #[Test]
    public function an_age_of_zero_is_rejected(): void
    {
        $parent = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(CreateChildProfile::class)->handle($parent, $this->data(birthDate: now()->subMonths(6)->toDateString()));
    }

    #[Test]
    public function an_age_of_nineteen_is_rejected(): void
    {
        $parent = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(CreateChildProfile::class)->handle($parent, $this->data(birthDate: now()->subYears(19)->toDateString()));
    }

    #[Test]
    public function ages_one_and_eighteen_are_both_accepted(): void
    {
        $parent = User::factory()->create();

        $one = app(CreateChildProfile::class)->handle($parent, $this->data(name: 'One', birthDate: now()->subYears(1)->toDateString()));
        $eighteen = app(CreateChildProfile::class)->handle($parent, $this->data(name: 'Eighteen', birthDate: now()->subYears(18)->toDateString()));

        $this->assertDatabaseHas('player_profiles', ['id' => $one->id, 'name' => 'One']);
        $this->assertDatabaseHas('player_profiles', ['id' => $eighteen->id, 'name' => 'Eighteen']);
    }

    #[Test]
    public function a_matching_name_and_birth_year_within_the_same_family_requires_confirmation(): void
    {
        $parent = User::factory()->create();
        PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Alex Doe', 'birth_date' => '2015-03-01']);

        $threw = false;

        try {
            app(CreateChildProfile::class)->handle($parent, $this->data(name: '  alex   doe ', birthDate: '2015-11-20'));
        } catch (DuplicateChildProfileException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected a DuplicateChildProfileException to be thrown.');
        $this->assertSame(1, $parent->guardedPlayerProfiles()->count());

        $confirmed = app(CreateChildProfile::class)->handle(
            $parent,
            $this->data(name: '  alex   doe ', birthDate: '2015-11-20', confirmDuplicate: true)
        );

        $this->assertDatabaseHas('player_profiles', ['id' => $confirmed->id]);
        $this->assertSame(2, $parent->guardedPlayerProfiles()->count());
    }

    #[Test]
    public function an_unrelated_familys_matching_name_never_triggers_the_warning(): void
    {
        $stranger = User::factory()->create();
        PlayerProfile::factory()->child()->guardedBy($stranger)->create(['name' => 'Alex Doe', 'birth_date' => '2015-03-01']);

        $parent = User::factory()->create();

        $profile = app(CreateChildProfile::class)->handle($parent, $this->data(name: 'Alex Doe', birthDate: '2015-03-01'));

        $this->assertDatabaseHas('player_profiles', ['id' => $profile->id]);
    }

    #[Test]
    public function a_single_trainer_parent_who_opts_in_is_associated_with_exactly_that_trainer(): void
    {
        [$parent, $trainer] = $this->parentWithOneTrainer();

        $profile = app(CreateChildProfile::class)->handle($parent, $this->data(trainerProfileIds: [$trainer->id]));

        $this->assertSame(1, TrainerPlayer::withoutGlobalScopes()->where('player_profile_id', $profile->id)->count());
    }

    #[Test]
    public function declining_all_trainers_leaves_the_child_unassociated(): void
    {
        [$parent] = $this->parentWithOneTrainer();

        $profile = app(CreateChildProfile::class)->handle($parent, $this->data(trainerProfileIds: []));

        $this->assertSame(0, TrainerPlayer::withoutGlobalScopes()->where('player_profile_id', $profile->id)->count());
    }

    #[Test]
    public function a_multi_trainer_parent_associates_exactly_the_selected_trainers(): void
    {
        $parent = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($parent)->create();
        $trainerA = TrainerProfile::factory()->create();
        $trainerB = TrainerProfile::factory()->create();

        app(AssociatePlayersWithTrainer::class)->handle($trainerA, $parent, [$self->id]);
        app(AssociatePlayersWithTrainer::class)->handle($trainerB, $parent, [$self->id]);

        $profile = app(CreateChildProfile::class)->handle($parent, $this->data(trainerProfileIds: [$trainerB->id]));

        $this->assertSame(0, TrainerPlayer::withoutGlobalScopes()
            ->where(['player_profile_id' => $profile->id, 'trainer_profile_id' => $trainerA->id])->count());
        $this->assertSame(1, TrainerPlayer::withoutGlobalScopes()
            ->where(['player_profile_id' => $profile->id, 'trainer_profile_id' => $trainerB->id])->count());
    }

    #[Test]
    public function creating_with_a_login_writes_both_flags_together(): void
    {
        $parent = User::factory()->create();

        $profile = app(CreateChildProfile::class)->handle($parent, $this->data(
            wantsLogin: true,
            loginEmail: 'kid@example.test',
            loginPassword: 'correct-horse-battery-staple',
            loginPasswordConfirmation: 'correct-horse-battery-staple',
        ))->fresh();

        $child = $profile->user;

        $this->assertNotNull($child);
        $this->assertTrue($profile->is_child);
        $this->assertTrue($child->is_child_account);
    }

    /**
     * FR-011. `ChildForm` creates the login without a `Registered` event, so `email_verified_at`
     * would otherwise stay null forever and the login could never pass the `verified` middleware
     * that guards `/family` and `/approvals` — unreachable in practice even though FR-011 requires
     * a child to see its own purchase-status transitions there. The guardian's own verified account
     * is what vouches for the address.
     */
    #[Test]
    public function a_child_login_is_created_already_verified(): void
    {
        $parent = User::factory()->create();

        $profile = app(CreateChildProfile::class)->handle($parent, $this->data(
            wantsLogin: true,
            loginEmail: 'verified-kid@example.test',
            loginPassword: 'correct-horse-battery-staple',
            loginPasswordConfirmation: 'correct-horse-battery-staple',
        ))->fresh();

        $this->assertNotNull($profile->user);
        $this->assertNotNull($profile->user->email_verified_at);
    }

    /**
     * A second account-creation surface next to `/join/{code}` (AD-004): an authenticated guardian
     * could otherwise create unlimited `Active` `User` rows on arbitrary addresses. Mirrors
     * `JoinHardeningTest::repeated_registrations_from_one_address_are_throttled`.
     */
    #[Test]
    public function repeated_child_login_creation_from_one_address_is_throttled(): void
    {
        $parent = User::factory()->create();

        foreach (range(1, 5) as $i) {
            app(CreateChildProfile::class)->handle($parent, $this->data(
                name: "Kid {$i}",
                wantsLogin: true,
                loginEmail: "kid{$i}@example.test",
                loginPassword: 'correct-horse-battery-staple',
                loginPasswordConfirmation: 'correct-horse-battery-staple',
            ));
        }

        $this->expectException(ValidationException::class);

        try {
            app(CreateChildProfile::class)->handle($parent, $this->data(
                name: 'Kid 6',
                wantsLogin: true,
                loginEmail: 'kid6@example.test',
                loginPassword: 'correct-horse-battery-staple',
                loginPasswordConfirmation: 'correct-horse-battery-staple',
            ));
        } finally {
            $this->assertDatabaseMissing('users', ['email' => 'kid6@example.test']);
            $this->assertDatabaseMissing('player_profiles', ['name' => 'Kid 6']);

            RateLimiter::clear('family:child-login:'.$parent->id.':127.0.0.1');
        }
    }

    /** The rejection itself is audited, not just enforced silently. */
    #[Test]
    public function the_throttled_rejection_is_logged(): void
    {
        $parent = User::factory()->create();

        foreach (range(1, 5) as $i) {
            app(CreateChildProfile::class)->handle($parent, $this->data(
                name: "Kid {$i}",
                wantsLogin: true,
                loginEmail: "kid{$i}@example.test",
                loginPassword: 'correct-horse-battery-staple',
                loginPasswordConfirmation: 'correct-horse-battery-staple',
            ));
        }

        try {
            app(CreateChildProfile::class)->handle($parent, $this->data(
                name: 'Kid 6',
                wantsLogin: true,
                loginEmail: 'kid6@example.test',
                loginPassword: 'correct-horse-battery-staple',
                loginPasswordConfirmation: 'correct-horse-battery-staple',
            ));

            $this->fail('Expected the sixth submission to be throttled.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'family.child-login-throttled',
            ]);
        } finally {
            RateLimiter::clear('family:child-login:'.$parent->id.':127.0.0.1');
        }
    }

    /**
     * The limiter key carries the acting guardian's id, not just the IP: a club's guardians share
     * one NAT address, and one guardian's five submissions must not lock the rest of them out.
     */
    #[Test]
    public function one_guardians_throttle_does_not_block_another_guardian_on_the_same_address(): void
    {
        $noisyGuardian = User::factory()->create();
        $otherGuardian = User::factory()->create();

        foreach (range(1, 5) as $i) {
            app(CreateChildProfile::class)->handle($noisyGuardian, $this->data(
                name: "Noisy Kid {$i}",
                wantsLogin: true,
                loginEmail: "noisykid{$i}@example.test",
                loginPassword: 'correct-horse-battery-staple',
                loginPasswordConfirmation: 'correct-horse-battery-staple',
            ));
        }

        try {
            $profile = app(CreateChildProfile::class)->handle($otherGuardian, $this->data(
                name: 'Quiet Kid',
                wantsLogin: true,
                loginEmail: 'quietkid@example.test',
                loginPassword: 'correct-horse-battery-staple',
                loginPasswordConfirmation: 'correct-horse-battery-staple',
            ));

            $this->assertNotNull($profile->fresh()->user_id);
        } finally {
            RateLimiter::clear('family:child-login:'.$noisyGuardian->id.':127.0.0.1');
            RateLimiter::clear('family:child-login:'.$otherGuardian->id.':127.0.0.1');
        }
    }

    /** A child profile created with no login at all must never be throttled by the login guard. */
    #[Test]
    public function creating_children_without_a_login_is_never_throttled(): void
    {
        $parent = User::factory()->create();

        foreach (range(1, 6) as $i) {
            $profile = app(CreateChildProfile::class)->handle($parent, $this->data(name: "Loginless Kid {$i}"));

            $this->assertNull($profile->fresh()->user_id);
        }

        $this->assertSame(6, $parent->guardedPlayerProfiles()->count());
    }

    /** Mirrors ChildAccountInvariantTest, but calls the action directly rather than seeding. */
    #[Test]
    public function the_two_child_flags_never_disagree_coming_out_of_this_action(): void
    {
        $parent = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $profile = app(CreateChildProfile::class)->handle($parent, $this->data(
                name: "Kid {$i}",
                wantsLogin: true,
                loginEmail: "kid{$i}@example.test",
                loginPassword: 'correct-horse-battery-staple',
                loginPasswordConfirmation: 'correct-horse-battery-staple',
            ))->fresh();

            $this->assertSame($profile->is_child, $profile->user?->is_child_account);
        }
    }

    /**
     * Decision 8's regression: `trainableProfiles()` is memoized per instance, and the duplicate
     * check above already reads the guardian's family before this new child exists. Without
     * `resetTrainableProfilesCache()`, the association loop re-derives the stale, pre-child set and
     * silently drops the very child it just created.
     */
    #[Test]
    public function creating_a_child_and_associating_it_with_a_trainer_in_the_same_request_succeeds(): void
    {
        [$parent, $trainer] = $this->parentWithOneTrainer();

        // Primes the memoized cache with the pre-child family, mirroring one HTTP request where
        // trainableProfiles() was already read once (e.g. by the context middleware) before this
        // action runs.
        $parent->trainableProfiles();

        $profile = app(CreateChildProfile::class)->handle($parent, $this->data(trainerProfileIds: [$trainer->id]));

        $this->assertDatabaseHas('trainer_players', [
            'player_profile_id' => $profile->id,
            'trainer_profile_id' => $trainer->id,
        ]);
    }

    /** @return array{0: User, 1: TrainerProfile} */
    private function parentWithOneTrainer(): array
    {
        $parent = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($parent)->create();
        $trainer = TrainerProfile::factory()->create();

        app(AssociatePlayersWithTrainer::class)->handle($trainer, $parent, [$self->id]);

        return [$parent, $trainer];
    }

    /** @param list<int> $trainerProfileIds */
    private function data(
        string $name = 'Test Child',
        ?string $birthDate = null,
        string $gender = 'female',
        array $trainerProfileIds = [],
        bool $confirmDuplicate = false,
        bool $wantsLogin = false,
        ?string $loginEmail = null,
        ?string $loginPassword = null,
        ?string $loginPasswordConfirmation = null,
    ): ChildProfileData {
        return new ChildProfileData(
            name: $name,
            birthDate: $birthDate ?? now()->subYears(10)->toDateString(),
            gender: $gender,
            school: null,
            jerseyNumber: null,
            emergencyContact: null,
            trainerProfileIds: $trainerProfileIds,
            confirmDuplicate: $confirmDuplicate,
            wantsLogin: $wantsLogin,
            loginEmail: $loginEmail,
            loginPassword: $loginPassword,
            loginPasswordConfirmation: $loginPasswordConfirmation,
        );
    }
}
