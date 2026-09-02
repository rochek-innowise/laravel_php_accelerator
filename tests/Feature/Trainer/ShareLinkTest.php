<?php

declare(strict_types=1);

namespace Tests\Feature\Trainer;

use App\Actions\ShareLink\GeneratePlayerShareLink;
use App\Enums\ShareLinkType;
use App\Livewire\Trainer\ShareLinks;
use App\Models\ShareLink;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\Tenancy\TrainerContext;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShareLinkTest extends TestCase
{
    #[Test]
    public function a_trainer_gets_a_static_unlimited_link(): void
    {
        [$user, $trainer] = $this->trainer();

        $link = app(GeneratePlayerShareLink::class)->handle($trainer, $user);

        $this->assertSame(ShareLinkType::Player, $link->type);
        $this->assertNull($link->expires_at, 'BR-008: a player link does not expire.');
        $this->assertNull($link->max_uses, 'BR-008: a player link has unlimited uses.');
        $this->assertTrue($link->isRedeemable());
    }

    #[Test]
    public function the_code_is_high_entropy_and_unique(): void
    {
        $codes = collect(range(1, 25))->map(fn (): string => ShareLink::mintCode());

        $this->assertCount(25, $codes->unique());
        $this->assertSame([ShareLink::CODE_BYTES * 2], $codes->map(fn (string $c): int => strlen($c))->unique()->all());
    }

    /** A GET carries no CSRF token, so rendering the screen must not commit anything. */
    #[Test]
    public function opening_the_screen_never_mints_a_link(): void
    {
        [$user, $trainer] = $this->trainer();

        $this->actingAs($user);
        app(TrainerContext::class)->set($trainer);

        Livewire::test(ShareLinks::class)->assertOk()->assertSee('No invitation link yet');

        $this->assertSame(0, ShareLink::withoutGlobalScopes()->count());
    }

    #[Test]
    public function reading_the_existing_link_twice_returns_the_same_row(): void
    {
        [$user, $trainer] = $this->trainer();
        $generate = app(GeneratePlayerShareLink::class);

        $minted = $generate->handle($trainer, $user);

        $first = $generate->existing($trainer);
        $second = $generate->existing($trainer);

        $this->assertNotNull($first);
        $this->assertSame($minted->id, $first->id);
        $this->assertSame($minted->id, $second?->id);
        $this->assertSame(1, ShareLink::withoutGlobalScopes()->count());
    }

    #[Test]
    public function regenerating_deactivates_the_previous_code(): void
    {
        [$user, $trainer] = $this->trainer();
        $generate = app(GeneratePlayerShareLink::class);

        $old = $generate->handle($trainer, $user);
        $new = $generate->handle($trainer, $user);

        $this->assertNotSame($old->code, $new->code);
        $this->assertFalse($old->fresh()->is_active, 'A replaced link must stop working, or revocation is a lie.');
        $this->assertTrue($new->fresh()->is_active);
    }

    #[Test]
    public function a_trainer_cannot_see_another_organisations_links(): void
    {
        [$mine, $myTrainer] = $this->trainer();
        [, $theirTrainer] = $this->trainer();

        ShareLink::factory()->create(['trainer_profile_id' => $myTrainer->id]);
        ShareLink::factory()->count(3)->create(['trainer_profile_id' => $theirTrainer->id]);

        app(TrainerContext::class)->set($myTrainer);
        $this->actingAs($mine);

        $this->assertSame(1, ShareLink::query()->count());
    }

    #[Test]
    public function the_screen_renders_the_link_once_it_exists(): void
    {
        [$user, $trainer] = $this->trainer();

        $this->actingAs($user);
        app(TrainerContext::class)->set($trainer);

        app(GeneratePlayerShareLink::class)->handle($trainer, $user);

        Livewire::test(ShareLinks::class)
            ->assertOk()
            ->assertSee('Your player invitation link');
    }

    #[Test]
    public function a_non_trainer_is_refused_the_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('trainer.share-links'))
            ->assertForbidden();
    }

    #[Test]
    public function the_audit_trail_records_the_generation(): void
    {
        [$user, $trainer] = $this->trainer();

        $this->actingAs($user);
        app(GeneratePlayerShareLink::class)->handle($trainer, $user);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'share-link.generated',
        ]);
    }

    /** @return array{0: User, 1: TrainerProfile} */
    protected function trainer(): array
    {
        $user = User::factory()->trainer()->create();

        return [$user, TrainerProfile::factory()->create(['user_id' => $user->id])];
    }
}
