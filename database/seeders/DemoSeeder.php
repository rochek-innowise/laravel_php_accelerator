<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\ShareLink\GeneratePlayerShareLink;
use App\Enums\TrainerPlayerStatus;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\ShareLink;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The hard scenario, end to end: if a change breaks isolation or the family model, this seeder
 * is what makes it visible. Availability arrives with Slice D.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->superAdmin()->create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin@example.test',
        ]);

        $trainerOne = TrainerProfile::factory()->create(['business_name' => 'Elite Basketball Academy']);
        TrainerProfile::factory()->create(['business_name' => 'Northside Volleyball']);

        CoachProfile::factory()->create(['trainer_profile_id' => $trainerOne->id]);

        $parent = User::factory()->create([
            'first_name' => 'Sarah',
            'last_name' => 'Miles',
            'email' => 'parent@example.test',
        ]);

        // Every Player/Parent account gets a self profile, so "parent who also trains" (BR-022)
        // is the ordinary case rather than a branch.
        PlayerProfile::factory()->selfProfile($parent)->create();

        PlayerProfile::factory()->child()->guardedBy($parent, relationship: 'mother')->create([
            'name' => 'Alex Miles',
        ]);

        $secondGuardian = User::factory()->create([
            'first_name' => 'Taras',
            'last_name' => 'Miles',
            'email' => 'parent2@example.test',
        ]);

        $secondChildLogin = User::factory()->childAccount()->create([
            'first_name' => 'Maya',
            'last_name' => 'Miles',
            'email' => 'child@example.test',
        ]);

        // Maya has two guardians, which is the case `owner_user_id` could not express.
        $maya = PlayerProfile::factory()
            ->child()
            ->guardedBy($parent, relationship: 'mother')
            ->guardedBy($secondGuardian, isPrimary: false, relationship: 'father')
            ->create([
                'user_id' => $secondChildLogin->id,
                'name' => 'Maya Miles',
            ]);

        $this->seedAssociations($trainerOne, $parent, $maya);
    }

    /**
     * Slice B's half of the scenario: a static player link per organisation, a pending coach
     * invitation, and one child reachable from *both* organisations — the case that catches a
     * tenancy regression, because Maya must appear in each roster without either seeing the other.
     */
    protected function seedAssociations(TrainerProfile $trainerOne, User $parent, PlayerProfile $maya): void
    {
        $trainerTwo = TrainerProfile::where('business_name', 'Northside Volleyball')->firstOrFail();
        $owner = $trainerOne->user()->firstOrFail();

        $generate = app(GeneratePlayerShareLink::class);
        $linkOne = $generate->handle($trainerOne, $owner);
        $generate->handle($trainerTwo, $trainerTwo->user()->firstOrFail());

        $parentProfile = $parent->playerProfile()->firstOrFail();

        // The parent trains with one organisation; Maya trains with both.
        $this->associate($trainerOne, $parentProfile->id, $linkOne->id);
        $this->associate($trainerOne, $maya->id, $linkOne->id);
        $this->associate($trainerTwo, $maya->id, null);

        // A coach invitation left pending, so the Coaches screen has all three states to render.
        ShareLink::factory()->coach('pending.coach@example.test')->create([
            'trainer_profile_id' => $trainerOne->id,
            'created_by_user_id' => $owner->id,
        ]);
    }

    protected function associate(TrainerProfile $trainer, int $playerProfileId, ?int $shareLinkId): void
    {
        $association = new TrainerPlayer(['status' => TrainerPlayerStatus::Active]);

        $association->forceFill([
            'trainer_profile_id' => $trainer->id,
            'player_profile_id' => $playerProfileId,
            'share_link_id' => $shareLinkId,
            'connected_at' => now(),
        ])->save();
    }
}
