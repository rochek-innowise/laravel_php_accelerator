<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\EnsureTrainerContext;
use App\Livewire\Context\ProfileSwitcher;
use App\Livewire\Context\TrainerSwitcher;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContextSwitcherTest extends TestCase
{
    #[Test]
    public function a_parent_sees_every_organisation_the_family_joined(): void
    {
        [$parent, $self] = $this->playerWithProfile();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create();

        $mine = $this->associate($self, 'Elite Basketball');
        $childs = $this->associate($child, 'Northside Volleyball');

        $this->actingAs($parent);

        Livewire::test(TrainerSwitcher::class)
            ->assertSee($mine->business_name)
            ->assertSee($childs->business_name);
    }

    #[Test]
    public function switching_changes_the_resolved_organisation(): void
    {
        [$parent, $self] = $this->playerWithProfile();

        $first = $this->associate($self, 'First Org');
        $second = $this->associate($self, 'Second Org');

        Livewire::actingAs($parent)
            ->test(TrainerSwitcher::class)
            ->call('switch', $second->id);

        $this->assertSame($second->id, session(EnsureTrainerContext::SESSION_KEY));

        $this->actingAs($parent)->get(route('profile'))->assertOk();

        $resolved = app(TrainerContext::class)->get();

        $this->assertNotNull($resolved);
        $this->assertSame($second->id, $resolved->id);
        $this->assertNotSame($first->id, $resolved->id);
    }

    #[Test]
    public function switching_to_an_organisation_the_family_never_joined_is_refused(): void
    {
        [$parent, $self] = $this->playerWithProfile();
        $this->associate($self);

        $strangers = TrainerProfile::factory()->create();

        Livewire::actingAs($parent)
            ->test(TrainerSwitcher::class)
            ->call('switch', $strangers->id)
            ->assertForbidden();

        // Refused, not silently ignored: a rejected switch must leave no trace in the session.
        $this->assertNotSame($strangers->id, session(EnsureTrainerContext::SESSION_KEY));
    }

    #[Test]
    public function a_trainer_sees_no_switcher(): void
    {
        $trainer = User::factory()->trainer()->create();
        TrainerProfile::factory()->create(['user_id' => $trainer->id]);

        Livewire::actingAs($trainer)
            ->test(TrainerSwitcher::class)
            ->assertViewHas('visible', false);
    }

    #[Test]
    public function a_coach_sees_no_switcher(): void
    {
        $coach = User::factory()->coach()->create();
        CoachProfile::factory()->create(['user_id' => $coach->id]);

        Livewire::actingAs($coach)
            ->test(TrainerSwitcher::class)
            ->assertViewHas('visible', false);
    }

    #[Test]
    public function one_organisation_is_not_a_choice(): void
    {
        [$parent, $self] = $this->playerWithProfile();
        $this->associate($self);

        Livewire::actingAs($parent)
            ->test(TrainerSwitcher::class)
            ->assertViewHas('visible', false);
    }

    /**
     * G-08: the switcher touches `trainer_players` and `trainer_profiles` and nothing else. An
     * event count beside an organisation's name would be a second organisation's data appearing
     * inside the first's context, so the rule is asserted against the queries rather than the copy.
     */
    #[Test]
    public function the_switcher_reads_only_the_membership_and_the_organisation_name(): void
    {
        [$parent, $self] = $this->playerWithProfile();
        $this->associate($self, 'One');
        $this->associate($self, 'Two');

        $this->actingAs($parent);

        $tables = [];
        DB::listen(function ($query) use (&$tables): void {
            // Every table named anywhere in the statement, joins included — the point is what the
            // switcher is allowed to read, not how a particular relation phrases its SQL.
            preg_match_all('/(?:from|join) `([a-z_]+)`/', $query->sql, $matches);
            $tables = array_merge($tables, $matches[1]);
        });

        Livewire::test(TrainerSwitcher::class)->assertOk();

        $permitted = ['player_guardians', 'player_profiles', 'trainer_players', 'trainer_profiles', 'users'];
        $read = collect($tables)->unique()->sort()->values()->all();

        $this->assertSame(
            [],
            array_values(array_diff($read, $permitted)),
            'The trainer switcher must expose an organisation name and logo and nothing else (G-08).',
        );
        $this->assertContains('trainer_players', $read, 'Membership is what the switcher is built on.');
        $this->assertContains('trainer_profiles', $read);
    }

    #[Test]
    public function the_profile_switcher_lists_only_members_training_in_this_organisation(): void
    {
        [$parent, $self] = $this->playerWithProfile();
        $here = PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Alex Here']);
        $elsewhere = PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Maya Elsewhere']);

        $tenant = TrainerProfile::factory()->create();
        foreach ([$self, $here] as $profile) {
            TrainerPlayer::factory()->create([
                'trainer_profile_id' => $tenant->id,
                'player_profile_id' => $profile->id,
            ]);
        }
        $this->associate($elsewhere);

        $this->actingAs($parent);
        app(TrainerContext::class)->set($tenant);

        Livewire::test(ProfileSwitcher::class)
            ->assertSee('Alex Here')
            ->assertDontSee('Maya Elsewhere');
    }

    #[Test]
    public function the_profile_switcher_is_hidden_with_no_organisation(): void
    {
        [$parent, $self] = $this->playerWithProfile();
        $this->associate($self);

        Livewire::actingAs($parent)
            ->test(ProfileSwitcher::class)
            ->assertViewHas('visible', false);
    }

    protected function associate(PlayerProfile $profile, ?string $businessName = null): TrainerProfile
    {
        $tenant = TrainerProfile::factory()->create(
            $businessName !== null ? ['business_name' => $businessName] : []
        );

        TrainerPlayer::factory()->create([
            'trainer_profile_id' => $tenant->id,
            'player_profile_id' => $profile->id,
        ]);

        return $tenant;
    }

    /** @return array{0: User, 1: PlayerProfile} */
    protected function playerWithProfile(): array
    {
        $user = User::factory()->create();

        return [$user, PlayerProfile::factory()->selfProfile($user)->create()];
    }
}
