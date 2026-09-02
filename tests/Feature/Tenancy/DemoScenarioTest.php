<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\TrainerProfile;
use App\Support\Tenancy\TrainerContext;
use Database\Seeders\DemoSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The seeded scenario is the end-to-end check the design asks for: if a change breaks isolation or
 * the family model, this is where it shows. Maya trains with both organisations, which is the case
 * a tenancy regression cannot survive.
 */
final class DemoScenarioTest extends TestCase
{
    #[Test]
    public function the_demo_scenario_seeds_and_stays_isolated(): void
    {
        $this->seed(DemoSeeder::class);

        $one = TrainerProfile::where('business_name', 'Elite Basketball Academy')->firstOrFail();
        $two = TrainerProfile::where('business_name', 'Northside Volleyball')->firstOrFail();

        $context = app(TrainerContext::class);

        $rosterOne = $context->runFor($one, fn (): array => $one->playerProfiles()->pluck('name')->all());
        $rosterTwo = $context->runFor($two, fn (): array => $two->playerProfiles()->pluck('name')->all());

        $this->assertContains('Maya Miles', $rosterOne);
        $this->assertContains('Maya Miles', $rosterTwo, 'One child, two organisations — BR-005.');

        // The parent trains with the first organisation only, so the second must not see them.
        $this->assertContains('Sarah Miles', $rosterOne);
        $this->assertNotContains('Sarah Miles', $rosterTwo);

        $this->assertSame(3, count($rosterOne) + count($rosterTwo));
    }

    #[Test]
    public function each_organisation_has_its_own_player_link(): void
    {
        $this->seed(DemoSeeder::class);

        $context = app(TrainerContext::class);

        foreach (TrainerProfile::all() as $trainer) {
            $links = $context->runFor($trainer, fn (): int => $trainer->shareLinks()->where('is_active', true)->count());

            $this->assertGreaterThanOrEqual(1, $links, $trainer->business_name.' has no invitation link.');
        }
    }
}
