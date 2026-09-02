<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentType;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\PurchaseApproval;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Tests\TestCase;

/** The privilege columns decide who you are, so they must not be reachable by mass assignment. */
final class MassAssignmentTest extends TestCase
{
    public function test_role_cannot_be_mass_assigned_on_create(): void
    {
        $user = new User([
            'email' => 'escalate@example.test',
            'password' => 'password',
            'first_name' => 'Es',
            'last_name' => 'Calate',
            'role' => Role::SuperAdmin,
        ]);

        $this->assertArrayNotHasKey('role', $user->getAttributes());
    }

    public function test_role_and_status_cannot_be_mass_assigned_on_update(): void
    {
        $user = User::factory()->create();

        $user->update([
            'role' => Role::SuperAdmin,
            'status' => UserStatus::Inactive,
            'is_child_account' => true,
            'first_name' => 'Legitimate',
        ]);

        $fresh = $user->fresh();

        $this->assertSame(Role::Player, $fresh->role);
        $this->assertSame(UserStatus::Active, $fresh->status);
        $this->assertFalse($fresh->is_child_account);
        $this->assertSame('Legitimate', $fresh->first_name);
    }

    /**
     * These columns are the tenancy boundary: a request-supplied user_id would let one account
     * claim another family's child, and a request-supplied trainer_profile_id would place a coach
     * inside someone else's organisation — the leakage NFR-010 puts at 0%.
     */
    public function test_profile_owner_columns_cannot_be_mass_assigned(): void
    {
        $victim = User::factory()->create();

        $player = new PlayerProfile([
            'name' => 'Claimed Child',
            'user_id' => $victim->id,
        ]);

        $this->assertSame('Claimed Child', $player->name);
        $this->assertArrayNotHasKey('user_id', $player->getAttributes());

        $coach = new CoachProfile([
            'status' => 'active',
            'user_id' => $victim->id,
            'trainer_profile_id' => 99,
        ]);

        $this->assertArrayNotHasKey('user_id', $coach->getAttributes());
        $this->assertArrayNotHasKey('trainer_profile_id', $coach->getAttributes());

        $trainer = new TrainerProfile([
            'business_name' => 'Claimed Academy',
            'user_id' => $victim->id,
        ]);

        $this->assertArrayNotHasKey('user_id', $trainer->getAttributes());
    }

    /** An audit row guards every attribute, so a stray mass assignment fails loudly. */
    public function test_an_audit_row_cannot_be_mass_assigned_at_all(): void
    {
        $this->expectException(MassAssignmentException::class);

        new AuditLog(['action' => 'forged', 'actor_user_id' => 1]);
    }

    /** Factories and seeders run unguarded, so the change above must not break them. */
    public function test_factories_still_populate_owner_columns(): void
    {
        $profile = CoachProfile::factory()->create();

        $this->assertTrue($profile->user()->exists());
        $this->assertTrue($profile->trainerProfile()->exists());
    }

    /**
     * AD-016: `token_spend_requires_approval` decides whether a spend skips parental approval
     * entirely, and `user_id` decides whose child this is — neither may move through `update()`.
     * Asserted through the actual `fill()`/`update()` behaviour (the value the database keeps
     * afterwards), not by reading `getFillable()`, which would only restate the allow-list rather
     * than prove mass assignment is refused.
     */
    public function test_player_profile_privilege_and_owner_columns_are_refused_by_mass_assignment(): void
    {
        $victim = User::factory()->create();
        $profile = PlayerProfile::factory()->child()->create([
            'token_spend_requires_approval' => true,
        ]);
        $originalUserId = $profile->user_id;

        $profile->update([
            'token_spend_requires_approval' => false,
            'user_id' => $victim->id,
            'name' => 'Legitimately Renamed',
        ]);

        $fresh = $profile->fresh();
        $this->assertTrue($fresh->token_spend_requires_approval);
        $this->assertSame($originalUserId, $fresh->user_id);
        $this->assertSame('Legitimately Renamed', $fresh->name);
    }

    /**
     * AD-016: `status`, `player_profile_id`, `amount_cents` and `payment_type` decide the outcome
     * of a purchase and who it belongs to — a request-supplied `status` would let a child approve
     * their own purchase. `parent_note` is the sole exception. Asserted through `update()`
     * behaviour, not `getFillable()`, for the same reason as above.
     */
    public function test_purchase_approval_refuses_mass_assignment_of_everything_but_parent_note(): void
    {
        $approval = PurchaseApproval::factory()->create();
        $otherProfile = PlayerProfile::factory()->create();

        $originalStatus = $approval->status;
        $originalAmountCents = $approval->amount_cents;
        $originalPlayerProfileId = $approval->player_profile_id;
        $originalPaymentType = $approval->payment_type;

        $approval->update([
            'status' => ApprovalStatus::Approved,
            'amount_cents' => 999_999,
            'player_profile_id' => $otherProfile->id,
            'payment_type' => PaymentType::Token,
            'parent_note' => 'Approved for the school trip.',
        ]);

        $fresh = $approval->fresh();
        $this->assertSame($originalStatus, $fresh->status);
        $this->assertSame($originalAmountCents, $fresh->amount_cents);
        $this->assertSame($originalPlayerProfileId, $fresh->player_profile_id);
        $this->assertSame($originalPaymentType, $fresh->payment_type);
        $this->assertSame('Approved for the school trip.', $fresh->parent_note);
    }
}
