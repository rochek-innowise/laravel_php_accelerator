<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use App\Actions\Availability\SaveAvailability;
use App\Enums\DayOfWeek;
use App\Models\AuditLog;
use App\Models\Availability;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Services\Availability\AvailabilityResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Decision 3's write side: delete-then-replace, never a row-level patch. "Reset to default" is
 * exactly an empty `$ranges` array against a non-null `trainer_profile_id`.
 */
final class SaveAvailabilityTest extends TestCase
{
    #[Test]
    public function saving_a_default_set_wholesale_replaces_the_previous_one(): void
    {
        $subject = PlayerProfile::factory()->create();
        $stale = Availability::factory()->forSubject($subject)->create([
            'day_of_week' => DayOfWeek::Friday,
        ]);

        app(SaveAvailability::class)->handle($subject, null, [
            ['day_of_week' => DayOfWeek::Monday->value, 'start_time' => '17:00:00', 'end_time' => '20:00:00', 'is_available' => true],
            ['day_of_week' => DayOfWeek::Wednesday->value, 'start_time' => '17:00:00', 'end_time' => '20:00:00', 'is_available' => true],
        ]);

        $this->assertDatabaseMissing('availabilities', ['id' => $stale->id]);

        $rows = app(AvailabilityResolver::class)->resolve($subject, null);
        $this->assertCount(2, $rows);
        $this->assertSame(
            [DayOfWeek::Monday, DayOfWeek::Wednesday],
            $rows->pluck('day_of_week')->all()
        );

        $log = AuditLog::where('action', 'availability.saved')->first();
        $this->assertNotNull($log);
        $this->assertSame($subject->getKey(), $log->subject_id);
        $this->assertSame(PlayerProfile::class, $log->subject_type);
        $this->assertNull($log->metadata['trainer_profile_id']);
        $this->assertSame(2, $log->metadata['day_count']);
    }

    #[Test]
    public function saving_an_override_does_not_touch_the_default_set(): void
    {
        $subject = PlayerProfile::factory()->create();
        $trainer = TrainerProfile::factory()->create();

        $default = Availability::factory()->forSubject($subject)->create([
            'day_of_week' => DayOfWeek::Monday,
        ]);

        app(SaveAvailability::class)->handle($subject, $trainer->id, [
            ['day_of_week' => DayOfWeek::Tuesday->value, 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'is_available' => true],
        ]);

        $this->assertDatabaseHas('availabilities', ['id' => $default->id]);

        $resolver = app(AvailabilityResolver::class);
        $this->assertSame([$default->id], $resolver->resolve($subject, null)->pluck('id')->all());
        $this->assertSame(DayOfWeek::Tuesday, $resolver->resolve($subject, $trainer->id)->first()->day_of_week);
    }

    #[Test]
    public function resetting_to_default_is_an_empty_ranges_array_against_the_override_slot(): void
    {
        $subject = PlayerProfile::factory()->create();
        $trainer = TrainerProfile::factory()->create();

        Availability::factory()->forSubject($subject)->create(['day_of_week' => DayOfWeek::Monday]);
        Availability::factory()->forSubject($subject)->override($trainer)->create(['day_of_week' => DayOfWeek::Tuesday]);

        $resolver = app(AvailabilityResolver::class);
        $this->assertFalse($resolver->isUsingDefault($subject, $trainer->id));

        app(SaveAvailability::class)->handle($subject, $trainer->id, []);

        $this->assertTrue($resolver->isUsingDefault($subject, $trainer->id));
        $this->assertSame(DayOfWeek::Monday, $resolver->resolve($subject, $trainer->id)->first()->day_of_week);
    }
}
