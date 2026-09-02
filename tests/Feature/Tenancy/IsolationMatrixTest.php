<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Livewire\Trainer\Coaches;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\ShareLink;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NFR-010 asks for 0% cross-organisation leakage. This is the matrix that holds the claim: for
 * every tenant-owned model, trainer A holding a valid id belonging to trainer B must come up empty.
 *
 * The property being pinned is AD-011: because the global scope applies during route-model binding
 * and relation resolution, the miss happens **by construction**, before any ownership check a
 * controller might forget. A new tenant-owned model added without a row here is the gap to catch
 * in review.
 */
final class IsolationMatrixTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function tenantOwnedModels(): array
    {
        return [
            'coach profiles' => [CoachProfile::class],
            'share links' => [ShareLink::class],
            'trainer players' => [TrainerPlayer::class],
        ];
    }

    #[Test]
    #[DataProvider('tenantOwnedModels')]
    public function another_organisations_row_is_invisible(string $model): void
    {
        [$mine, $theirs] = $this->twoOrganisations();

        $theirRow = $this->rowFor($model, $theirs);

        app(TrainerContext::class)->set($mine);

        $this->assertNull(
            $model::query()->find($theirRow->getKey()),
            $model.' resolved a row belonging to another organisation.'
        );
        $this->assertSame(0, $model::query()->count());
    }

    #[Test]
    #[DataProvider('tenantOwnedModels')]
    public function a_row_is_visible_inside_its_own_organisation(string $model): void
    {
        [$mine] = $this->twoOrganisations();

        $row = $this->rowFor($model, $mine);

        app(TrainerContext::class)->set($mine);

        $this->assertNotNull($model::query()->find($row->getKey()));
    }

    /**
     * The same property through the HTTP layer with a real session, which is where a forgotten
     * ownership check would actually show up.
     */
    #[Test]
    public function a_trainer_cannot_act_on_another_organisations_coach_over_http(): void
    {
        [$mineProfile, $theirsProfile] = $this->twoOrganisations();
        $me = User::factory()->trainer()->create();
        $mineProfile->forceFill(['user_id' => $me->id])->save();

        $theirCoach = CoachProfile::factory()->create(['trainer_profile_id' => $theirsProfile->id]);

        $this->actingAs($me);
        app(TrainerContext::class)->set($mineProfile);

        // The lookup misses before any policy runs, so this surfaces as a 404 rather than a 403 —
        // the trainer is not told the row exists. That is AD-011 working as intended.
        $refused = false;

        try {
            Livewire::test(Coaches::class)->call('release', $theirCoach->id);
        } catch (ModelNotFoundException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'A cross-organisation id must not resolve.');
        $this->assertSame(
            $theirCoach->status,
            $theirCoach->fresh()->status,
            'A cross-organisation write must leave the row untouched.'
        );
    }

    #[Test]
    public function a_trainers_roster_never_reads_player_profiles_directly(): void
    {
        [$mine, $theirs] = $this->twoOrganisations();

        $myPlayer = PlayerProfile::factory()->create(['name' => 'Mine']);
        $theirPlayer = PlayerProfile::factory()->create(['name' => 'Theirs']);

        TrainerPlayer::factory()->create([
            'trainer_profile_id' => $mine->id,
            'player_profile_id' => $myPlayer->id,
        ]);
        TrainerPlayer::factory()->create([
            'trainer_profile_id' => $theirs->id,
            'player_profile_id' => $theirPlayer->id,
        ]);

        app(TrainerContext::class)->set($mine);

        // PlayerProfile is identity and deliberately unscoped, so reading it directly would show
        // both people. Reachability is the association row (AD-001) — this is what a roster query
        // must look like.
        $roster = $mine->playerProfiles()->pluck('name')->all();

        $this->assertSame(['Mine'], $roster);
        $this->assertSame(2, PlayerProfile::query()->count(), 'PlayerProfile itself stays unscoped.');
    }

    /** @return array{0: TrainerProfile, 1: TrainerProfile} */
    protected function twoOrganisations(): array
    {
        return [TrainerProfile::factory()->create(), TrainerProfile::factory()->create()];
    }

    protected function rowFor(string $model, TrainerProfile $tenant): Model
    {
        return $model::factory()->create(['trainer_profile_id' => $tenant->id]);
    }
}
