<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The hard scenario, end to end: if a change breaks isolation or the family model, this seeder
 * is what makes it visible. Multi-trainer associations and availability arrive with Slices B/D.
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

        $childLogin = User::factory()->childAccount()->create([
            'first_name' => 'Maya',
            'last_name' => 'Miles',
            'email' => 'child@example.test',
        ]);

        // Maya has two guardians, which is the case `owner_user_id` could not express.
        PlayerProfile::factory()
            ->child()
            ->guardedBy($parent, relationship: 'mother')
            ->guardedBy($secondGuardian, isPrimary: false, relationship: 'father')
            ->create([
                'user_id' => $childLogin->id,
                'name' => 'Maya Miles',
            ]);
    }
}
