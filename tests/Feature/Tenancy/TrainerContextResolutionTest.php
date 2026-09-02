<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\EnsureTrainerContext;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One case per resolution rule (AD-001). The cases that matter most are the negative ones: a coach
 * who has not accepted yet, and a player whose association was revoked while their session lived.
 */
final class TrainerContextResolutionTest extends TestCase
{
    /** Reads the tenant the middleware resolved for a real request, rather than calling the
     * middleware directly — the session re-validation only means something through the stack. */
    protected function resolvedTenantFor(User $user): ?TrainerProfile
    {
        $this->actingAs($user)->get(route('profile'))->assertOk();

        return app(TrainerContext::class)->get();
    }

    #[Test]
    public function a_trainer_resolves_to_their_own_organisation(): void
    {
        $trainer = User::factory()->trainer()->create();
        $profile = TrainerProfile::factory()->create(['user_id' => $trainer->id]);

        $this->assertSame($profile->id, $this->resolvedTenantFor($trainer)?->id);
    }

    #[Test]
    public function an_active_coach_resolves_to_their_employer(): void
    {
        $coach = User::factory()->coach()->create();
        $employer = TrainerProfile::factory()->create();
        CoachProfile::factory()->create([
            'user_id' => $coach->id,
            'trainer_profile_id' => $employer->id,
        ]);

        $this->assertSame($employer->id, $this->resolvedTenantFor($coach)?->id);
    }

    #[Test]
    public function an_invited_coach_has_no_organisation_yet(): void
    {
        $coach = User::factory()->coach()->create();
        CoachProfile::factory()->invited()->create(['user_id' => $coach->id]);

        $this->assertNull($this->resolvedTenantFor($coach));
    }

    #[Test]
    public function a_super_admin_has_no_organisation(): void
    {
        $this->assertNull($this->resolvedTenantFor(User::factory()->superAdmin()->create()));
    }

    #[Test]
    public function a_player_with_no_associations_has_no_organisation(): void
    {
        $player = User::factory()->create();
        PlayerProfile::factory()->selfProfile($player)->create();

        $this->assertNull($this->resolvedTenantFor($player));
    }

    #[Test]
    public function a_player_falls_back_to_their_earliest_association(): void
    {
        [$player, $profile] = $this->playerWithProfile();

        $second = $this->associate($profile, connectedAt: now()->subDay());
        $first = $this->associate($profile, connectedAt: now()->subWeek());

        $resolved = $this->resolvedTenantFor($player);

        $this->assertNotNull($resolved);
        $this->assertSame($first->id, $resolved->id);
        $this->assertNotSame($second->id, $resolved->id);
    }

    #[Test]
    public function a_player_resolves_to_the_organisation_held_in_the_session(): void
    {
        [$player, $profile] = $this->playerWithProfile();

        $this->associate($profile, connectedAt: now()->subWeek());
        $chosen = $this->associate($profile, connectedAt: now()->subDay());

        $this->actingAs($player)
            ->withSession([EnsureTrainerContext::SESSION_KEY => $chosen->id])
            ->get(route('profile'))
            ->assertOk();

        $this->assertSame($chosen->id, app(TrainerContext::class)->get()?->id);
    }

    #[Test]
    public function a_revoked_association_stops_resolving_on_the_next_request(): void
    {
        [$player, $profile] = $this->playerWithProfile();

        $kept = $this->associate($profile, connectedAt: now()->subWeek());
        $revoked = $this->associate($profile, connectedAt: now()->subDay());

        // The session still names the revoked organisation — exactly the stale value a switcher
        // leaves behind when a trainer removes someone mid-session.
        TrainerPlayer::withoutGlobalScopes()
            ->where('trainer_profile_id', $revoked->id)
            ->delete();

        $this->actingAs($player)
            ->withSession([EnsureTrainerContext::SESSION_KEY => $revoked->id])
            ->get(route('profile'))
            ->assertOk();

        $this->assertSame($kept->id, app(TrainerContext::class)->get()?->id);
    }

    #[Test]
    public function a_session_naming_an_organisation_the_player_never_joined_is_ignored(): void
    {
        [$player, $profile] = $this->playerWithProfile();

        $mine = $this->associate($profile);
        $strangers = TrainerProfile::factory()->create();

        $this->actingAs($player)
            ->withSession([EnsureTrainerContext::SESSION_KEY => $strangers->id])
            ->get(route('profile'))
            ->assertOk();

        $this->assertSame($mine->id, app(TrainerContext::class)->get()?->id);
    }

    #[Test]
    public function a_guardian_reaches_the_organisations_of_the_children_they_guard(): void
    {
        $parent = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create();

        $tenant = $this->associate($child);

        $this->assertSame($tenant->id, $this->resolvedTenantFor($parent)?->id);
    }

    #[Test]
    public function an_inactive_association_does_not_resolve(): void
    {
        [$player, $profile] = $this->playerWithProfile();

        $tenant = TrainerProfile::factory()->create();
        TrainerPlayer::factory()->inactive()->create([
            'trainer_profile_id' => $tenant->id,
            'player_profile_id' => $profile->id,
        ]);

        $this->assertNull($this->resolvedTenantFor($player));
    }

    /** @return array{0: User, 1: PlayerProfile} */
    protected function playerWithProfile(): array
    {
        $player = User::factory()->create();

        return [$player, PlayerProfile::factory()->selfProfile($player)->create()];
    }

    protected function associate(PlayerProfile $profile, ?Carbon $connectedAt = null): TrainerProfile
    {
        $tenant = TrainerProfile::factory()->create();

        TrainerPlayer::factory()->create([
            'trainer_profile_id' => $tenant->id,
            'player_profile_id' => $profile->id,
            'connected_at' => $connectedAt ?? now(),
        ]);

        return $tenant;
    }
}
