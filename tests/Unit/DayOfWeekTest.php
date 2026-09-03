<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DayOfWeek;
use PHPUnit\Framework\TestCase;

final class DayOfWeekTest extends TestCase
{
    public function test_every_case_matches_carbons_day_of_week_convention(): void
    {
        $this->assertSame(0, DayOfWeek::Sunday->value);
        $this->assertSame(1, DayOfWeek::Monday->value);
        $this->assertSame(2, DayOfWeek::Tuesday->value);
        $this->assertSame(3, DayOfWeek::Wednesday->value);
        $this->assertSame(4, DayOfWeek::Thursday->value);
        $this->assertSame(5, DayOfWeek::Friday->value);
        $this->assertSame(6, DayOfWeek::Saturday->value);
    }

    public function test_every_case_has_a_label_and_a_short_label(): void
    {
        foreach (DayOfWeek::cases() as $day) {
            $this->assertNotSame('', $day->label());
            $this->assertNotSame('', $day->shortLabel());
        }
    }

    public function test_short_labels_are_the_three_letter_form(): void
    {
        $this->assertSame('Mon', DayOfWeek::Monday->shortLabel());
        $this->assertSame('Sun', DayOfWeek::Sunday->shortLabel());
        $this->assertSame('Sat', DayOfWeek::Saturday->shortLabel());
    }

    public function test_labels_are_distinct_per_case(): void
    {
        $labels = array_map(fn (DayOfWeek $day): string => $day->label(), DayOfWeek::cases());

        $this->assertSame($labels, array_unique($labels));
    }
}
