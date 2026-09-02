<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\PlayerProfile;
use App\Models\PurchaseApproval;
use App\Models\User;
use App\Policies\PurchaseApprovalPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PurchaseApproval is owner-scoped, not tenant-owned (AD-001's third data class): the only
 * boundary here is guardian/child ownership, plus the state guard on `respond` that makes a
 * resolved row show no action buttons at all.
 */
final class PurchaseApprovalPolicyTest extends TestCase
{
    protected PurchaseApprovalPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new PurchaseApprovalPolicy;
    }

    #[Test]
    public function a_guardian_may_view_and_respond_to_a_pending_request(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        $this->assertTrue($guardian->can('view', $approval));
        $this->assertTrue($guardian->can('respond', $approval));
    }

    #[Test]
    public function the_child_may_view_but_never_respond(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        $this->assertTrue($childLogin->can('view', $approval));
        $this->assertFalse($childLogin->can('respond', $approval));
    }

    #[Test]
    public function a_stranger_may_neither_view_nor_respond(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        $stranger = User::factory()->create();

        $this->assertFalse($stranger->can('view', $approval));
        $this->assertFalse($stranger->can('respond', $approval));
    }

    #[Test]
    public function a_guardian_cannot_respond_once_the_row_is_no_longer_pending(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->approved()->create(['player_profile_id' => $child->id]);

        $this->assertTrue($guardian->can('view', $approval));
        $this->assertFalse($guardian->can('respond', $approval));
    }

    /** FR-011: a child login never manages an approval even on a resolved row it may only view. */
    #[Test]
    public function the_child_still_cannot_respond_once_resolved(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->denied()->create(['player_profile_id' => $child->id]);

        $this->assertFalse($childLogin->can('respond', $approval));
    }

    #[Test]
    public function the_policy_itself_does_not_grant_a_super_admin(): void
    {
        $guardian = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $childLogin->id]);
        $approval = PurchaseApproval::factory()->create(['player_profile_id' => $child->id]);

        $admin = User::factory()->superAdmin()->create();

        $this->assertFalse($this->policy->view($admin, $approval));
        $this->assertFalse($this->policy->respond($admin, $approval));
        $this->assertTrue($admin->can('view', $approval));
        $this->assertTrue($admin->can('respond', $approval));
    }
}
