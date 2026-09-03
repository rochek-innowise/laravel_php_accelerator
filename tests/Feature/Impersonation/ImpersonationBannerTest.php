<?php

declare(strict_types=1);

namespace Tests\Feature\Impersonation;

use App\Models\User;
use Tests\TestCase;

/**
 * The banner component itself, tested in isolation — it is not yet included in the shared
 * layout (that is Slice D's final integration step, after Track D also lands).
 */
final class ImpersonationBannerTest extends TestCase
{
    public function test_it_renders_during_an_active_impersonation_session(): void
    {
        $target = User::factory()->create(['first_name' => 'Target', 'last_name' => 'User']);
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($target);
        $this->session(['impersonator_id' => $admin->id]);

        $html = (string) $this->blade('<x-impersonation-banner />');

        $this->assertStringContainsString('Viewing as', $html);
        $this->assertStringContainsString('Target User', $html);
        $this->assertStringContainsString('Exit Impersonation', $html);
        $this->assertStringContainsString(route('impersonate.stop'), $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('border-foul', $html);
    }

    public function test_it_renders_nothing_outside_an_impersonation_session(): void
    {
        $this->actingAs(User::factory()->create());

        $html = (string) $this->blade('<x-impersonation-banner />');

        $this->assertSame('', trim($html));
    }
}
