<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Exceptions\TenantContextMissingException;
use App\Models\CoachProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The single most important property in Slice B: with no resolved organisation a tenant-owned
 * query returns **nothing**, not everything (AD-001). Every other isolation guarantee is built on
 * this one, so it is asserted directly rather than inferred from a screen.
 */
final class FailClosedScopeTest extends TestCase
{
    #[Test]
    public function a_query_with_no_context_returns_no_rows(): void
    {
        CoachProfile::factory()->count(3)->create();

        $this->assertSame(0, CoachProfile::query()->count());
        $this->assertCount(0, CoachProfile::all());
    }

    #[Test]
    public function a_query_sees_only_its_own_organisation(): void
    {
        $mine = TrainerProfile::factory()->create();
        $theirs = TrainerProfile::factory()->create();

        CoachProfile::factory()->count(2)->create(['trainer_profile_id' => $mine->id]);
        CoachProfile::factory()->count(5)->create(['trainer_profile_id' => $theirs->id]);

        $context = app(TrainerContext::class);
        $context->set($mine);

        $this->assertSame(2, CoachProfile::query()->count());
    }

    #[Test]
    public function creating_fills_the_tenant_from_the_context(): void
    {
        $tenant = TrainerProfile::factory()->create();
        $coach = User::factory()->coach()->create();

        app(TrainerContext::class)->runFor($tenant, function () use ($coach): void {
            $coach->coachProfile()->create(['status' => 'invited']);
        });

        $this->assertDatabaseHas('coach_profiles', [
            'user_id' => $coach->id,
            'trainer_profile_id' => $tenant->id,
        ]);
    }

    #[Test]
    public function creating_without_a_context_throws_rather_than_writing_an_unowned_row(): void
    {
        $coach = User::factory()->coach()->create();

        $this->expectException(TenantContextMissingException::class);

        $coach->coachProfile()->create(['status' => 'invited']);
    }

    #[Test]
    public function run_as_system_sees_across_organisations(): void
    {
        CoachProfile::factory()->count(4)->create();

        $seen = app(TrainerContext::class)->runAsSystem(
            fn (): int => CoachProfile::query()->count()
        );

        $this->assertSame(4, $seen);
        $this->assertSame(0, CoachProfile::query()->count(), 'Suppression must not leak past the closure.');
    }

    #[Test]
    public function without_tenant_scope_is_refused_to_a_non_admin(): void
    {
        CoachProfile::factory()->count(2)->create();

        $this->actingAs(User::factory()->trainer()->create());

        $this->expectException(AuthorizationException::class);

        CoachProfile::withoutTenantScope()->count();
    }

    #[Test]
    public function without_tenant_scope_is_allowed_to_a_super_admin(): void
    {
        CoachProfile::factory()->count(2)->create();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertSame(2, CoachProfile::withoutTenantScope()->count());
    }
}
