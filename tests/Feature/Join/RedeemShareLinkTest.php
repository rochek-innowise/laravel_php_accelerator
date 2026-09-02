<?php

declare(strict_types=1);

namespace Tests\Feature\Join;

use App\Actions\ShareLink\RedeemShareLink as RedeemAction;
use App\Exceptions\ShareLinkNotRedeemableException;
use App\Http\Middleware\EnsureTrainerContext;
use App\Livewire\Join\RedeemShareLink;
use App\Models\PlayerProfile;
use App\Models\ShareLink;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Notifications\ChildShareLinkBlocked;
use App\Notifications\JoinedTrainer;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RedeemShareLinkTest extends TestCase
{
    #[Test]
    public function a_guest_registers_and_is_associated(): void
    {
        Notification::fake();
        $link = $this->playerLink();

        Livewire::test(RedeemShareLink::class, ['code' => $link->code])
            ->set('first_name', 'Dana')
            ->set('last_name', 'Reyes')
            ->set('email', 'dana@example.test')
            ->set('player_name', 'Dana Reyes')
            ->set('password', 'correct-horse-battery-staple')
            ->set('password_confirmation', 'correct-horse-battery-staple')
            ->call('register')
            ->assertHasNoErrors();

        $user = User::where('email', 'dana@example.test')->firstOrFail();

        $this->assertDatabaseHas('player_profiles', ['user_id' => $user->id, 'name' => 'Dana Reyes']);
        $this->assertDatabaseHas('trainer_players', [
            'trainer_profile_id' => $link->trainer_profile_id,
            'share_link_id' => $link->id,
        ]);
    }

    #[Test]
    public function an_existing_account_joins_a_second_organisation_without_a_duplicate_account(): void
    {
        Notification::fake();
        [$user, $profile] = $this->playerWithProfile();

        $first = $this->playerLink();
        $second = $this->playerLink();

        $this->joinAs($user, $first, [$profile->id]);
        $this->joinAs($user, $second, [$profile->id]);

        // BR-007: one person, one account, two associations.
        $this->assertSame(1, User::where('email', $user->email)->count());
        $this->assertSame(2, TrainerPlayer::withoutGlobalScopes()->where('player_profile_id', $profile->id)->count());
    }

    #[Test]
    public function redeeming_the_same_link_twice_is_idempotent(): void
    {
        Notification::fake();
        [$user, $profile] = $this->playerWithProfile();
        $link = $this->playerLink();

        $this->joinAs($user, $link, [$profile->id]);
        $this->joinAs($user, $link, [$profile->id]);

        $this->assertSame(1, TrainerPlayer::withoutGlobalScopes()->where('player_profile_id', $profile->id)->count());
    }

    #[Test]
    public function a_parent_enrols_only_the_selected_family_members(): void
    {
        Notification::fake();
        $parent = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($parent)->create();
        $chosen = PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Alex']);
        $skipped = PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Maya']);

        $link = $this->playerLink();

        $this->joinAs($parent, $link, [$self->id, $chosen->id]);

        $enrolled = TrainerPlayer::withoutGlobalScopes()->pluck('player_profile_id')->all();

        $this->assertContains($self->id, $enrolled);
        $this->assertContains($chosen->id, $enrolled);
        $this->assertNotContains($skipped->id, $enrolled, 'BR-023: associations are explicit.');
    }

    #[Test]
    public function a_profile_outside_the_family_is_ignored(): void
    {
        Notification::fake();
        [$parent, $self] = $this->playerWithProfile();
        $strangersChild = PlayerProfile::factory()->child()->create();

        $link = $this->playerLink();

        $this->joinAs($parent, $link, [$self->id, $strangersChild->id]);

        $this->assertDatabaseMissing('trainer_players', ['player_profile_id' => $strangersChild->id]);
        $this->assertDatabaseHas('trainer_players', ['player_profile_id' => $self->id]);
    }

    /**
     * FR-011, deliberately changed in Slice C: this used to assert a bare 403. A child account is
     * now told what to do next instead of met with a wall, and every guardian is notified with the
     * link so they can complete the registration themselves — the ordinary checklist flow above,
     * nothing new on their side.
     */
    #[Test]
    public function a_child_account_is_blocked_with_friendly_copy_and_guardians_are_notified(): void
    {
        Notification::fake();
        [$child, $profile, $guardian] = $this->childWithProfile();
        $link = $this->playerLink();

        Livewire::actingAs($child)
            ->test(RedeemShareLink::class, ['code' => $link->code])
            ->set('selectedProfileIds', [$profile->id])
            ->call('join')
            ->assertOk()
            ->assertSee('Ask your parent to register you with this trainer');

        $this->assertDatabaseCount('trainer_players', 0);
        Notification::assertSentTo($guardian, ChildShareLinkBlocked::class);
    }

    #[Test]
    public function an_inactive_link_cannot_be_redeemed(): void
    {
        [$user, $profile] = $this->playerWithProfile();
        $link = ShareLink::factory()->inactive()->create();

        Livewire::actingAs($user)
            ->test(RedeemShareLink::class, ['code' => $link->code])
            ->assertSee('Invitation not valid')
            ->set('selectedProfileIds', [$profile->id])
            ->call('join')
            ->assertStatus(410);

        $this->assertDatabaseCount('trainer_players', 0);
    }

    #[Test]
    public function an_expired_link_cannot_be_redeemed(): void
    {
        [$user, $profile] = $this->playerWithProfile();
        $link = ShareLink::factory()->coach()->expired()->create();

        Livewire::actingAs($user)
            ->test(RedeemShareLink::class, ['code' => $link->code])
            ->set('selectedProfileIds', [$profile->id])
            ->call('join')
            ->assertStatus(410);
    }

    #[Test]
    public function an_unknown_code_shows_the_invalid_screen_rather_than_a_404(): void
    {
        $this->get(route('join', ['code' => 'nothing-here']))
            ->assertOk()
            ->assertSee('Invitation not valid');
    }

    #[Test]
    public function a_single_use_link_is_spent_by_its_first_redemption(): void
    {
        Notification::fake();
        [$user, $profile] = $this->playerWithProfile();

        $link = ShareLink::factory()->create(['max_uses' => 1]);

        $this->joinAs($user, $link, [$profile->id]);

        $link->refresh();

        $this->assertSame(1, $link->uses_count);
        $this->assertFalse($link->is_active);
        $this->assertFalse($link->isRedeemable());
    }

    #[Test]
    public function a_redemption_that_enrols_nobody_does_not_spend_the_link(): void
    {
        [$user] = $this->playerWithProfile();
        $strangersChild = PlayerProfile::factory()->child()->create();

        $link = ShareLink::factory()->create(['max_uses' => 1]);

        Livewire::actingAs($user)
            ->test(RedeemShareLink::class, ['code' => $link->code])
            ->set('selectedProfileIds', [$strangersChild->id])
            ->call('join')
            ->assertHasErrors('selectedProfileIds');

        $link->refresh();

        $this->assertSame(0, $link->uses_count);
        $this->assertTrue($link->is_active);
    }

    #[Test]
    public function joining_sets_the_new_organisation_as_the_active_context(): void
    {
        Notification::fake();
        [$user, $profile] = $this->playerWithProfile();
        $link = $this->playerLink();

        $this->joinAs($user, $link, [$profile->id]);

        $this->assertSame($link->trainer_profile_id, session(EnsureTrainerContext::SESSION_KEY));
    }

    #[Test]
    public function the_confirmation_email_is_sent(): void
    {
        Notification::fake();
        [$user, $profile] = $this->playerWithProfile();

        $this->joinAs($user, $this->playerLink(), [$profile->id]);

        Notification::assertSentTo($user, JoinedTrainer::class);
    }

    /**
     * NFR-004: two concurrent redemptions of a one-shot link. The second connection blocks on the
     * first's `lockForUpdate` and, once released, sees the spent row — so exactly one succeeds.
     * Asserted through the action's own guard rather than a timing race.
     */
    #[Test]
    public function a_single_use_link_cannot_be_redeemed_twice(): void
    {
        Notification::fake();
        [$first, $firstProfile] = $this->playerWithProfile();
        [$second, $secondProfile] = $this->playerWithProfile();

        $link = ShareLink::factory()->create(['max_uses' => 1]);

        app(RedeemAction::class)->forPlayer($link->code, $first, [$firstProfile->id]);

        $this->expectException(ShareLinkNotRedeemableException::class);

        app(RedeemAction::class)->forPlayer($link->code, $second, [$secondProfile->id]);
    }

    protected function joinAs(User $user, ShareLink $link, array $profileIds): void
    {
        Livewire::actingAs($user)
            ->test(RedeemShareLink::class, ['code' => $link->code])
            ->set('selectedProfileIds', $profileIds)
            ->call('join')
            ->assertHasNoErrors();
    }

    protected function playerLink(): ShareLink
    {
        $trainer = TrainerProfile::factory()->create();

        return ShareLink::factory()->create(['trainer_profile_id' => $trainer->id]);
    }

    /** @return array{0: User, 1: PlayerProfile} */
    protected function playerWithProfile(): array
    {
        $user = User::factory()->create();

        return [$user, PlayerProfile::factory()->selfProfile($user)->create()];
    }

    /** @return array{0: User, 1: PlayerProfile, 2: User} */
    protected function childWithProfile(): array
    {
        $child = User::factory()->childAccount()->create();
        $guardian = User::factory()->create();

        $profile = PlayerProfile::factory()->child()->guardedBy($guardian)->create(['user_id' => $child->id]);

        return [$child, $profile, $guardian];
    }
}
