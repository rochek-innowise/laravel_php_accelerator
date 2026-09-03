<?php

declare(strict_types=1);

namespace Tests\Feature\Trainer;

use App\Livewire\Trainer\Branding;
use App\Models\TrainerProfile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gap 13 (defence-in-depth). Every writer today validates `/^#[0-9A-Fa-f]{6}$/` before
 * `primary_color` is ever set, so there is no live path to a stored non-hex value — but `{{ }}`
 * does not escape `;`/`{`/`}`/`(`/`)`/`:`, so a value that got past that gate somehow would inject
 * arbitrary CSS for every member of the organisation. These pin the fallback, exercised by
 * bypassing validation directly (`forceFill`) the way a future bug would.
 */
final class BrandColorHardeningTest extends TestCase
{
    #[Test]
    public function the_shared_layout_falls_back_to_the_platform_default_for_a_non_hex_stored_value(): void
    {
        // `primary_color` is `varchar(7)` — a real breakout payload could never fit past the write
        // path anyway, but this proves the *render*-time check rejects a bad value on its shape
        // alone, independent of what would fit in the column.
        $trainer = TrainerProfile::factory()->create();
        $trainer->forceFill(['primary_color' => '1;}a{b:'])->save();

        $this->actingAs($trainer->user)
            ->get('/profile')
            ->assertOk()
            ->assertSee('--brand-primary: '.config('branding.default_primary_color').';', false)
            ->assertDontSee('1;}a{b:', false);
    }

    #[Test]
    public function a_valid_stored_colour_still_renders_as_is(): void
    {
        $trainer = TrainerProfile::factory()->create(['primary_color' => '#112233']);

        $this->actingAs($trainer->user)
            ->get('/profile')
            ->assertOk()
            ->assertSee('--brand-primary: #112233;', false);
    }

    #[Test]
    public function the_branding_screens_swatch_falls_back_for_an_invalid_in_progress_value(): void
    {
        $trainer = TrainerProfile::factory()->create();

        Livewire::actingAs($trainer->user)
            ->test(Branding::class)
            ->set('primaryColor', 'red; } body { display:none')
            ->assertSee('background-color: '.config('branding.default_primary_color').';', false)
            ->assertDontSee('display:none', false);
    }

    #[Test]
    public function the_branding_screens_swatch_shows_a_valid_in_progress_value(): void
    {
        $trainer = TrainerProfile::factory()->create();

        Livewire::actingAs($trainer->user)
            ->test(Branding::class)
            ->set('primaryColor', '#ABCDEF')
            ->assertSee('background-color: #ABCDEF;', false);
    }
}
