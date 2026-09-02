<?php

declare(strict_types=1);

namespace Tests\Feature\Trainer;

use App\Actions\Trainer\AcceptCoachInvitation;
use App\Actions\Trainer\InviteCoach;
use App\Actions\Trainer\ReleaseCoach;
use App\Enums\CoachStatus;
use App\Enums\Role;
use App\Enums\ShareLinkType;
use App\Exceptions\ShareLinkNotRedeemableException;
use App\Livewire\Trainer\Coaches;
use App\Models\CoachProfile;
use App\Models\ShareLink;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Notifications\CoachInvitation;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CoachInvitationTest extends TestCase
{
    #[Test]
    public function an_invitation_is_single_use_and_expires_in_seven_days(): void
    {
        Notification::fake();
        [$user, $trainer] = $this->trainer();

        $link = app(InviteCoach::class)->handle($trainer, $user, 'coach@example.test');

        $this->assertSame(ShareLinkType::Coach, $link->type);
        $this->assertSame(1, $link->max_uses, 'BR-009: single use.');
        $this->assertTrue($link->expires_at?->isSameDay(now()->addDays(7)));
        $this->assertSame('coach@example.test', $link->target_email);
    }

    #[Test]
    public function the_invitation_email_carries_the_link(): void
    {
        Notification::fake();
        [$user, $trainer] = $this->trainer();

        app(InviteCoach::class)->handle($trainer, $user, 'coach@example.test', 'Join us for the spring season.');

        Notification::assertSentOnDemand(
            CoachInvitation::class,
            fn (CoachInvitation $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'coach@example.test'
        );
    }

    #[Test]
    public function accepting_makes_the_coach_active_under_that_organisation(): void
    {
        Notification::fake();
        [$user, $trainer] = $this->trainer();
        $link = app(InviteCoach::class)->handle($trainer, $user, 'coach@example.test');

        $coach = User::factory()->create(['email' => 'coach@example.test']);

        $profile = app(AcceptCoachInvitation::class)->handle($link->code, $coach);

        $this->assertSame(CoachStatus::Active, $profile->status);
        $this->assertSame($trainer->id, $profile->trainer_profile_id);
        $this->assertSame(Role::Coach, $coach->fresh()->role);
        $this->assertFalse($link->fresh()->is_active, 'A spent single-use link must be inert.');
    }

    #[Test]
    public function an_invitation_cannot_be_accepted_by_a_different_address(): void
    {
        Notification::fake();
        [$user, $trainer] = $this->trainer();
        $link = app(InviteCoach::class)->handle($trainer, $user, 'intended@example.test');

        $someoneElse = User::factory()->create(['email' => 'forwarded@example.test']);

        $this->expectException(ValidationException::class);

        app(AcceptCoachInvitation::class)->handle($link->code, $someoneElse);
    }

    /**
     * BR-006 at the level that actually holds it. This bypasses every action and writes straight to
     * the table: if the generated column and its unique index were ever dropped, only this test
     * would notice.
     */
    #[Test]
    public function the_database_refuses_a_second_active_row_for_one_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $first = TrainerProfile::factory()->create();
        $second = TrainerProfile::factory()->create();

        DB::table('coach_profiles')->insert([
            'user_id' => $coach->id,
            'trainer_profile_id' => $first->id,
            'status' => CoachStatus::Active->value,
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('coach_profiles')->insert([
            'user_id' => $coach->id,
            'trainer_profile_id' => $second->id,
            'status' => CoachStatus::Active->value,
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_database_permits_many_inactive_rows_for_one_coach(): void
    {
        $coach = User::factory()->coach()->create();

        foreach (range(1, 3) as $ignored) {
            DB::table('coach_profiles')->insert([
                'user_id' => $coach->id,
                'trainer_profile_id' => TrainerProfile::factory()->create()->id,
                'status' => CoachStatus::Inactive->value,
                'is_public' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(3, DB::table('coach_profiles')->where('user_id', $coach->id)->count());
    }

    #[Test]
    public function a_coach_active_elsewhere_cannot_accept_a_second_invitation(): void
    {
        Notification::fake();
        $coach = User::factory()->coach()->create(['email' => 'coach@example.test']);
        CoachProfile::factory()->create(['user_id' => $coach->id]);

        [$user, $trainer] = $this->trainer();
        $link = ShareLink::factory()->coach('coach@example.test')->create([
            'trainer_profile_id' => $trainer->id,
            'created_by_user_id' => $user->id,
        ]);

        $this->expectException(ValidationException::class);

        app(AcceptCoachInvitation::class)->handle($link->code, $coach);
    }

    #[Test]
    public function inviting_an_already_active_coach_is_a_field_error_not_a_server_error(): void
    {
        Notification::fake();
        $coach = User::factory()->coach()->create(['email' => 'coach@example.test']);
        CoachProfile::factory()->create(['user_id' => $coach->id]);

        [$user, $trainer] = $this->trainer();

        $this->actingAs($user);
        app(TrainerContext::class)->set($trainer);

        Livewire::test(Coaches::class)
            ->set('email', 'coach@example.test')
            ->call('invite')
            ->assertHasErrors('email');
    }

    /** G-11: an explicit release frees the slot, and the history stays. */
    #[Test]
    public function a_released_coach_may_join_another_organisation(): void
    {
        Notification::fake();
        $coach = User::factory()->coach()->create(['email' => 'coach@example.test']);
        $original = CoachProfile::factory()->create(['user_id' => $coach->id]);

        app(ReleaseCoach::class)->handle($original);

        [$user, $trainer] = $this->trainer();
        $link = app(InviteCoach::class)->handle($trainer, $user, 'coach@example.test');

        $profile = app(AcceptCoachInvitation::class)->handle($link->code, $coach);

        $this->assertSame(CoachStatus::Active, $profile->status);
        $this->assertSame($trainer->id, $profile->trainer_profile_id);
    }

    #[Test]
    public function an_expired_invitation_is_refused(): void
    {
        Notification::fake();
        [$user, $trainer] = $this->trainer();

        $link = ShareLink::factory()->coach('coach@example.test')->expired()->create([
            'trainer_profile_id' => $trainer->id,
            'created_by_user_id' => $user->id,
        ]);

        $coach = User::factory()->create(['email' => 'coach@example.test']);

        $this->expectException(ShareLinkNotRedeemableException::class);

        app(AcceptCoachInvitation::class)->handle($link->code, $coach);
    }

    #[Test]
    public function resending_supersedes_the_previous_link(): void
    {
        Notification::fake();
        [$user, $trainer] = $this->trainer();
        $original = app(InviteCoach::class)->handle($trainer, $user, 'coach@example.test');

        $this->actingAs($user);
        app(TrainerContext::class)->set($trainer);

        Livewire::test(Coaches::class)->call('resend', $original->id)->assertHasNoErrors();

        $this->assertFalse($original->fresh()->is_active);
        $this->assertSame(1, ShareLink::query()->where('is_active', true)->count());
    }

    #[Test]
    public function a_trainer_never_sees_another_organisations_invitations(): void
    {
        Notification::fake();
        [$mine, $myTrainer] = $this->trainer();
        [$theirs, $theirTrainer] = $this->trainer();

        app(InviteCoach::class)->handle($myTrainer, $mine, 'mine@example.test');
        app(InviteCoach::class)->handle($theirTrainer, $theirs, 'theirs@example.test');

        $this->actingAs($mine);
        app(TrainerContext::class)->set($myTrainer);

        Livewire::test(Coaches::class)
            ->assertSee('mine@example.test')
            ->assertDontSee('theirs@example.test');
    }

    #[Test]
    public function a_non_trainer_cannot_open_the_coaches_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('trainer.coaches'))
            ->assertForbidden();
    }

    /** @return array{0: User, 1: TrainerProfile} */
    protected function trainer(): array
    {
        $user = User::factory()->trainer()->create();

        return [$user, TrainerProfile::factory()->create(['user_id' => $user->id])];
    }
}
