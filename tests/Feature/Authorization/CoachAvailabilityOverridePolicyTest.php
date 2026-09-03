<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\CoachProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Policies\CoachAvailabilityOverridePolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-015. Exercised directly against the policy, not via `$user->can(...)` — see the policy's own
 * docblock for why `CoachProfile`'s existing registered policy would otherwise shadow this one.
 */
final class CoachAvailabilityOverridePolicyTest extends TestCase
{
    protected CoachAvailabilityOverridePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new CoachAvailabilityOverridePolicy;
    }

    #[Test]
    public function the_coachs_own_trainer_may_create_an_override(): void
    {
        $trainerProfile = TrainerProfile::factory()->create();
        $coach = CoachProfile::factory()->create(['trainer_profile_id' => $trainerProfile->id]);

        $this->assertTrue($this->policy->create($trainerProfile->user, $coach));
    }

    #[Test]
    public function a_trainer_from_another_organisation_is_refused(): void
    {
        $coach = CoachProfile::factory()->create();
        $stranger = TrainerProfile::factory()->create()->user;

        $this->assertFalse($this->policy->create($stranger, $coach));
    }

    #[Test]
    public function the_coach_themselves_may_not_create_their_own_override(): void
    {
        $coach = CoachProfile::factory()->create();

        $this->assertFalse($this->policy->create($coach->user, $coach));
    }

    #[Test]
    public function a_player_is_refused(): void
    {
        $coach = CoachProfile::factory()->create();
        $player = User::factory()->create();

        $this->assertFalse($this->policy->create($player, $coach));
    }

    /**
     * Gap 8: there is no `Gate::policy` registration, so convention discovery auto-binds this
     * class to `App\Models\CoachAvailabilityOverride` by name alone — a future
     * `authorize('create', $overrideRow)` reaching here must be refused, not fatal.
     */
    #[Test]
    public function an_unexpected_subject_type_is_refused_rather_than_fatal(): void
    {
        $trainerProfile = TrainerProfile::factory()->create();

        $this->assertFalse($this->policy->create($trainerProfile->user, $trainerProfile));
    }
}
