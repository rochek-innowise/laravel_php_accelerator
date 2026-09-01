<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\CoachProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Policies\CoachProfilePolicy;
use Tests\TestCase;

/**
 * `employs()` is the proto-tenancy check Slice B replaces with TrainerContext, so the boundary it
 * draws today — a trainer reaches only their own organisation's coaches — is pinned here.
 */
final class CoachProfilePolicyTest extends TestCase
{
    protected CoachProfilePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new CoachProfilePolicy;
    }

    public function test_a_coach_may_view_and_update_their_own_profile(): void
    {
        $coachProfile = CoachProfile::factory()->create();
        $coach = $coachProfile->user;

        $this->assertTrue($coach->can('view', $coachProfile));
        $this->assertTrue($coach->can('update', $coachProfile));
    }

    public function test_the_employing_trainer_may_view_and_update(): void
    {
        $trainerProfile = TrainerProfile::factory()->create();
        $coachProfile = CoachProfile::factory()->create(['trainer_profile_id' => $trainerProfile->id]);

        $employer = $trainerProfile->user;

        $this->assertTrue($employer->can('view', $coachProfile));
        $this->assertTrue($employer->can('update', $coachProfile));
    }

    public function test_a_trainer_from_another_organisation_is_refused(): void
    {
        $coachProfile = CoachProfile::factory()->create();
        $stranger = TrainerProfile::factory()->create()->user;

        $this->assertFalse($stranger->can('view', $coachProfile));
        $this->assertFalse($stranger->can('update', $coachProfile));
    }

    public function test_a_player_and_another_coach_are_refused(): void
    {
        $coachProfile = CoachProfile::factory()->create();

        foreach ([User::factory()->create(), User::factory()->coach()->create()] as $outsider) {
            $this->assertFalse($outsider->can('view', $coachProfile));
            $this->assertFalse($outsider->can('update', $coachProfile));
        }
    }

    public function test_only_a_trainer_may_list_or_invite_coaches(): void
    {
        $trainer = User::factory()->trainer()->create();

        $this->assertTrue($trainer->can('viewAny', CoachProfile::class));
        $this->assertTrue($trainer->can('invite', CoachProfile::class));

        foreach ([User::factory()->coach()->create(), User::factory()->create()] as $outsider) {
            $this->assertFalse($outsider->can('viewAny', CoachProfile::class));
            $this->assertFalse($outsider->can('invite', CoachProfile::class));
        }
    }

    /** The listing is Trainer-only by policy; a Super Admin gets there through the bypass. */
    public function test_the_policy_itself_does_not_grant_a_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->assertFalse($this->policy->viewAny($admin));
        $this->assertFalse($this->policy->invite($admin));
        $this->assertTrue($admin->can('viewAny', CoachProfile::class));
    }
}
