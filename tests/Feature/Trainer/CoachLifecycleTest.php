<?php

declare(strict_types=1);

namespace Tests\Feature\Trainer;

use App\Actions\ShareLink\GeneratePlayerShareLink;
use App\Actions\Trainer\AcceptCoachInvitation;
use App\Actions\Trainer\InviteCoach;
use App\Actions\Trainer\ReleaseCoach;
use App\Enums\CoachStatus;
use App\Enums\Role;
use App\Livewire\Trainer\Coaches;
use App\Models\CoachProfile;
use App\Models\ShareLink;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regressions for the coach-lifecycle defects found in review. Each of these passed silently
 * before: the suite asserted the happy path and never the state it left behind.
 */
final class CoachLifecycleTest extends TestCase
{
    #[Test]
    public function a_super_admin_cannot_be_demoted_by_a_coach_invitation(): void
    {
        Notification::fake();
        [$owner, $trainer] = $this->trainer();

        $admin = User::factory()->superAdmin()->create(['email' => 'admin@example.test']);
        $link = app(InviteCoach::class)->handle($trainer, $owner, 'admin@example.test');

        try {
            app(AcceptCoachInvitation::class)->handle($link->code, $admin);
            $this->fail('A Super Admin must not be able to accept a coaching invitation.');
        } catch (ValidationException) {
            // The refusal is the assertion; what matters is the state below.
        }

        $this->assertSame(Role::SuperAdmin, $admin->fresh()->role);
        $this->assertTrue($admin->fresh()->isSuperAdmin());
        $this->assertTrue($link->fresh()->is_active, 'A refused acceptance must not spend the link.');
    }

    #[Test]
    public function a_trainer_cannot_be_demoted_by_a_coach_invitation(): void
    {
        Notification::fake();
        [$owner, $trainer] = $this->trainer();
        [$victim] = $this->trainer('victim@example.test');

        $link = app(InviteCoach::class)->handle($trainer, $owner, 'victim@example.test');

        try {
            app(AcceptCoachInvitation::class)->handle($link->code, $victim);
            $this->fail('A Trainer must not be able to accept a coaching invitation.');
        } catch (ValidationException) {
            // As above.
        }

        $fresh = $victim->fresh();

        $this->assertSame(Role::Trainer, $fresh->role);
        $this->assertNotNull($fresh->trainerProfile, 'Their organisation must not be orphaned.');
    }

    /**
     * G-11 end to end. The released row stays as history and the re-hire adds a second, so the
     * relation must pick the active one — otherwise the coach resolves to no organisation at all
     * and, under fail-closed tenancy, every screen they open is empty.
     */
    #[Test]
    public function a_rehired_coach_resolves_to_their_new_organisation(): void
    {
        Notification::fake();

        $coach = User::factory()->coach()->create(['email' => 'coach@example.test']);
        $released = CoachProfile::factory()->create(['user_id' => $coach->id]);
        app(ReleaseCoach::class)->handle($released);

        [$owner, $newEmployer] = $this->trainer();
        $link = app(InviteCoach::class)->handle($newEmployer, $owner, 'coach@example.test');
        app(AcceptCoachInvitation::class)->handle($link->code, $coach);

        $this->assertSame(2, CoachProfile::withoutGlobalScopes()->where('user_id', $coach->id)->count());
        $this->assertSame(CoachStatus::Active, $coach->fresh()->coachProfile?->status);

        $this->actingAs($coach)->get(route('profile'))->assertOk();

        $this->assertSame($newEmployer->id, app(TrainerContext::class)->get()?->id);
    }

    /** The id is client-supplied, and the trainer's own player link is the dangerous target. */
    #[Test]
    public function resend_refuses_a_player_link_id(): void
    {
        Notification::fake();
        [$owner, $trainer] = $this->trainer();

        $playerLink = app(GeneratePlayerShareLink::class)->handle($trainer, $owner);

        $this->actingAs($owner);
        app(TrainerContext::class)->set($trainer);

        // The type filter makes the lookup miss, so this surfaces as a 404 before any policy runs
        // — the trainer is not told which of their own rows the id named.
        $refused = false;

        try {
            Livewire::test(Coaches::class)->call('resend', $playerLink->id);
        } catch (ModelNotFoundException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'A player link id must not resolve on the coach resend path.');
        $this->assertTrue($playerLink->fresh()->is_active, 'The permanent player link must survive.');
        $this->assertSame(0, ShareLink::query()->where('type', 'coach')->count());
    }

    #[Test]
    public function an_invitation_survives_a_resend_that_fails(): void
    {
        Notification::fake();
        [$owner, $trainer] = $this->trainer();

        $original = app(InviteCoach::class)->handle($trainer, $owner, 'coach@example.test');

        // The invitee takes a post elsewhere before the trainer clicks Resend.
        $coach = User::factory()->coach()->create(['email' => 'coach@example.test']);
        CoachProfile::factory()->create(['user_id' => $coach->id]);

        $this->actingAs($owner);
        app(TrainerContext::class)->set($trainer);

        Livewire::test(Coaches::class)->call('resend', $original->id)->assertHasErrors();

        $this->assertTrue(
            $original->fresh()->is_active,
            'A resend that could not issue a replacement must leave the original in place.'
        );
    }

    #[Test]
    public function an_invitation_matches_the_address_case_insensitively(): void
    {
        Notification::fake();
        [$owner, $trainer] = $this->trainer();

        $link = app(InviteCoach::class)->handle($trainer, $owner, 'Coach@Example.test');
        $coach = User::factory()->create(['email' => 'coach@example.test']);

        $profile = app(AcceptCoachInvitation::class)->handle($link->code, $coach);

        $this->assertSame(CoachStatus::Active, $profile->status);
    }

    #[Test]
    public function an_unverified_account_cannot_accept(): void
    {
        Notification::fake();
        [$owner, $trainer] = $this->trainer();

        $link = app(InviteCoach::class)->handle($trainer, $owner, 'coach@example.test');
        $coach = User::factory()->unverified()->create(['email' => 'coach@example.test']);

        $this->expectException(ValidationException::class);

        app(AcceptCoachInvitation::class)->handle($link->code, $coach);
    }

    #[Test]
    public function inviting_a_coach_who_already_works_here_says_so(): void
    {
        Notification::fake();
        [$owner, $trainer] = $this->trainer();

        $coach = User::factory()->coach()->create(['email' => 'coach@example.test']);
        CoachProfile::factory()->create(['user_id' => $coach->id, 'trainer_profile_id' => $trainer->id]);

        $this->actingAs($owner);
        app(TrainerContext::class)->set($trainer);

        Livewire::test(Coaches::class)
            ->set('email', 'coach@example.test')
            ->call('invite')
            ->assertHasErrors('email')
            ->assertSee('already active in your organisation');
    }

    /** @return array{0: User, 1: TrainerProfile} */
    protected function trainer(?string $email = null): array
    {
        $user = User::factory()->trainer()->create($email !== null ? ['email' => $email] : []);

        return [$user, TrainerProfile::factory()->create(['user_id' => $user->id])];
    }
}
