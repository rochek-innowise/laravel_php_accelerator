<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenancy resolution runs on every request in the `web` group — Livewire's update endpoint
 * included — and both switchers ask the same question the middleware just answered. Once, that
 * cost 11 queries a page load, nine of them the same three repeated three times.
 *
 * A budget rather than an exact count: the point is that the derivation happens once, not that the
 * number never moves. A regression here is a re-derivation somebody added, and it shows up as a
 * multiple.
 */
final class TenancyQueryBudgetTest extends TestCase
{
    #[Test]
    public function a_player_page_load_resolves_the_family_and_its_organisations_once(): void
    {
        $parent = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($parent)->create();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create();

        foreach ([$self, $child] as $profile) {
            foreach (range(1, 2) as $ignored) {
                TrainerPlayer::factory()->create([
                    'trainer_profile_id' => TrainerProfile::factory()->create()->id,
                    'player_profile_id' => $profile->id,
                ]);
            }
        }

        $this->actingAs($parent);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get(route('player.dashboard'))->assertOk();

        $familyLookups = $this->countMatching($queries, '/from `player_profiles`/');
        $membershipLookups = $this->countMatching($queries, '/from `trainer_p(layers|rofiles)`/');

        $this->assertLessThanOrEqual(
            2,
            $familyLookups,
            'The family should be derived once per request; more than one pass means something re-derived it.'
        );
        $this->assertLessThanOrEqual(
            2,
            $membershipLookups,
            'The organisation set is resolved by the middleware and cached on TrainerContext.'
        );
        $this->assertLessThanOrEqual(6, count($queries), 'Total tenancy bookkeeping for one page load.');
    }

    /** @param  list<string>  $queries */
    protected function countMatching(array $queries, string $pattern): int
    {
        return count(array_filter($queries, fn (string $sql): bool => preg_match($pattern, $sql) === 1));
    }
}
