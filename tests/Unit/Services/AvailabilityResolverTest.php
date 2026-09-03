<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\DayOfWeek;
use App\Models\Availability;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Services\Availability\AvailabilityResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice D Decision 3: a per-trainer override wholly replaces the default set — never a row-level
 * merge. The "both present" fixture below is the one that pins that explicitly.
 */
final class AvailabilityResolverTest extends TestCase
{
    protected AvailabilityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new AvailabilityResolver;
    }

    #[Test]
    public function default_only_resolves_to_the_default_set_for_any_trainer(): void
    {
        $subject = PlayerProfile::factory()->create();
        $trainer = TrainerProfile::factory()->create();

        $default = Availability::factory()->forSubject($subject)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
        ]);

        $this->assertSame([$default->id], $this->resolver->resolve($subject, null)->pluck('id')->all());
        $this->assertSame([$default->id], $this->resolver->resolve($subject, $trainer->id)->pluck('id')->all());
        $this->assertTrue($this->resolver->isUsingDefault($subject, null));
        $this->assertTrue($this->resolver->isUsingDefault($subject, $trainer->id));
    }

    #[Test]
    public function override_only_resolves_to_the_override_for_that_trainer_and_an_empty_default(): void
    {
        $subject = PlayerProfile::factory()->create();
        $trainer = TrainerProfile::factory()->create();

        $override = Availability::factory()->forSubject($subject)->override($trainer)->create([
            'day_of_week' => DayOfWeek::Tuesday,
        ]);

        $this->assertSame([$override->id], $this->resolver->resolve($subject, $trainer->id)->pluck('id')->all());
        $this->assertTrue($this->resolver->resolve($subject, null)->isEmpty());
        $this->assertFalse($this->resolver->isUsingDefault($subject, $trainer->id));
    }

    /** The case that matters most: an override replaces the default, it does not merge with it. */
    #[Test]
    public function both_present_resolves_to_the_override_alone_never_the_default_merged_in(): void
    {
        $subject = PlayerProfile::factory()->create();
        $trainer = TrainerProfile::factory()->create();
        $otherTrainer = TrainerProfile::factory()->create();

        $default = Availability::factory()->forSubject($subject)->create([
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '17:00:00',
            'end_time' => '20:00:00',
        ]);
        $override = Availability::factory()->forSubject($subject)->override($trainer)->create([
            'day_of_week' => DayOfWeek::Wednesday,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);

        $forTrainer = $this->resolver->resolve($subject, $trainer->id);
        $this->assertSame([$override->id], $forTrainer->pluck('id')->all(), 'Only the override row, never the default merged in.');
        $this->assertFalse($forTrainer->contains('id', $default->getKey()), 'The default row must not leak into the override result.');

        // A different trainer with no override of their own still falls back to the default.
        $forOtherTrainer = $this->resolver->resolve($subject, $otherTrainer->id);
        $this->assertSame([$default->id], $forOtherTrainer->pluck('id')->all());

        $forDefault = $this->resolver->resolve($subject, null);
        $this->assertSame([$default->id], $forDefault->pluck('id')->all());

        $this->assertFalse($this->resolver->isUsingDefault($subject, $trainer->id));
        $this->assertTrue($this->resolver->isUsingDefault($subject, $otherTrainer->id));
        $this->assertTrue($this->resolver->isUsingDefault($subject, null));
    }
}
