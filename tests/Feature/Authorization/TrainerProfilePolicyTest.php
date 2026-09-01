<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\TrainerProfile;
use App\Models\User;
use App\Policies\TrainerProfilePolicy;
use Tests\TestCase;

/**
 * The policy is exercised directly wherever the Super Admin Gate::before bypass would otherwise
 * mask what the policy itself decides — Slice B fills in the tenant branch and needs the current
 * behaviour pinned down first.
 */
final class TrainerProfilePolicyTest extends TestCase
{
    protected TrainerProfilePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new TrainerProfilePolicy;
    }

    public function test_the_owner_may_view_and_update_their_business_profile(): void
    {
        $profile = TrainerProfile::factory()->create();
        $owner = $profile->user;

        $this->assertTrue($owner->can('view', $profile));
        $this->assertTrue($owner->can('update', $profile));
        $this->assertTrue($owner->can('updateBranding', $profile));
    }

    public function test_a_trainer_from_another_organisation_is_refused(): void
    {
        $profile = TrainerProfile::factory()->create();
        $stranger = TrainerProfile::factory()->create()->user;

        $this->assertFalse($stranger->can('view', $profile));
        $this->assertFalse($stranger->can('update', $profile));
        $this->assertFalse($stranger->can('updateBranding', $profile));
    }

    public function test_a_coach_and_a_player_are_refused(): void
    {
        $profile = TrainerProfile::factory()->create();

        foreach ([User::factory()->coach()->create(), User::factory()->create()] as $outsider) {
            $this->assertFalse($outsider->can('view', $profile));
            $this->assertFalse($outsider->can('update', $profile));
            $this->assertFalse($outsider->can('updateBranding', $profile));
        }
    }

    /**
     * A Super Admin reads through the policy but writes only through the Gate::before bypass. If
     * that bypass is ever narrowed, this test says out loud what would break.
     */
    public function test_a_super_admin_reads_via_the_policy_and_writes_via_the_bypass(): void
    {
        $profile = TrainerProfile::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $this->assertTrue($this->policy->view($admin, $profile));
        $this->assertFalse($this->policy->update($admin, $profile));
        $this->assertFalse($this->policy->updateBranding($admin, $profile));

        $this->assertTrue($admin->can('update', $profile));
    }
}
