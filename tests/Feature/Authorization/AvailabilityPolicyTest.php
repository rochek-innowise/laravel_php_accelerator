<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Policies\AvailabilityPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-014/FR-015. Order: tenant membership -> role -> child deny list (AD-005) — but availability
 * is deliberately NOT on `ChildAbilities::DENIED`, so a child login may set their own Best Times,
 * unlike `manageTrainerAssociations`.
 *
 * Exercised directly against the policy, not via `$user->can('update', $subject)`: Laravel's Gate
 * resolves an ability purely from the subject's model class, and `PlayerProfile`/`CoachProfile`
 * already have their own registered policies (Slice A) — `$user->can('update', $playerProfile)`
 * would silently hit `PlayerProfilePolicy::update()`, not this one. `Livewire\Availability\Grid`
 * therefore calls this policy directly too (see its own docblock).
 */
final class AvailabilityPolicyTest extends TestCase
{
    protected AvailabilityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new AvailabilityPolicy;
    }

    #[Test]
    public function a_guardian_may_update_their_childs_availability(): void
    {
        $parent = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create();

        $this->assertTrue($this->policy->update($parent, $child));
    }

    #[Test]
    public function a_child_login_may_update_their_own_availability(): void
    {
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->create(['user_id' => $childLogin->id]);

        $this->assertTrue($this->policy->update($childLogin, $child));
    }

    #[Test]
    public function a_player_may_update_their_own_self_profile_availability(): void
    {
        $player = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($player)->create();

        $this->assertTrue($this->policy->update($player, $self));
    }

    #[Test]
    public function a_coach_may_update_their_own_availability(): void
    {
        $coachProfile = CoachProfile::factory()->create();

        $this->assertTrue($this->policy->update($coachProfile->user, $coachProfile));
    }

    #[Test]
    public function a_non_guardian_stranger_is_refused(): void
    {
        $stranger = User::factory()->create();
        $child = PlayerProfile::factory()->child()->create();

        $this->assertFalse($this->policy->update($stranger, $child));
    }

    #[Test]
    public function one_coach_may_not_update_anothers_availability(): void
    {
        $coachProfile = CoachProfile::factory()->create();
        $otherCoach = CoachProfile::factory()->create()->user;

        $this->assertFalse($this->policy->update($otherCoach, $coachProfile));
    }

    /**
     * Gap 8: there is no `Gate::policy` registration, so Laravel's convention discovery auto-binds
     * this class to `App\Models\Availability` by name alone — a future
     * `authorize('update', $availabilityRow)` reaching here must be refused, not fatal.
     */
    #[Test]
    public function an_unexpected_subject_type_is_refused_rather_than_fatal(): void
    {
        $user = User::factory()->create();
        $unexpectedSubject = TrainerProfile::factory()->create();

        $this->assertFalse($this->policy->update($user, $unexpectedSubject));
    }
}
