<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\TrainerPlayerStatus;
use App\Jobs\DeactivateRosterAssociations;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Support\Tenancy\TrainerContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AD-002 / AD-021. Fail-closed tenancy is a safety property inside a request and a trap outside
 * one: a job with no session sees no tenant, so every tenant-owned query returns nothing and the
 * job reports success having done nothing.
 *
 * Both halves are asserted, because a test that only proves the working path would let the failure
 * mode return the first time somebody writes a job without `runFor()`.
 */
final class QueuedJobTenancyTest extends TestCase
{
    #[Test]
    public function a_job_that_resolves_its_tenant_does_its_work(): void
    {
        [$tenant, $association] = $this->rosterRow();

        (new DeactivateRosterAssociations($tenant->id))->handle(app(TrainerContext::class));

        $this->assertSame(
            TrainerPlayerStatus::Inactive,
            TrainerPlayer::withoutGlobalScopes()->find($association->id)?->status
        );
    }

    #[Test]
    public function the_same_job_without_a_context_silently_does_nothing(): void
    {
        [, $association] = $this->rosterRow();

        // The same statement the job runs, but outside runFor() — which is exactly what a job
        // written without the wrapper does. Spelled out here rather than kept as a method on the
        // job, so production code carries no path whose only purpose is to fail.
        $affected = TrainerPlayer::query()->update(['status' => TrainerPlayerStatus::Inactive]);

        $this->assertSame(0, $affected, 'A context-less tenant query matches nothing — this is the trap.');
        $this->assertSame(
            TrainerPlayerStatus::Active,
            TrainerPlayer::withoutGlobalScopes()->find($association->id)?->status,
            'The row is untouched, yet the job would have reported success.'
        );
    }

    #[Test]
    public function a_job_only_touches_its_own_organisation(): void
    {
        [$mine, $mineRow] = $this->rosterRow();
        [, $theirRow] = $this->rosterRow();

        (new DeactivateRosterAssociations($mine->id))->handle(app(TrainerContext::class));

        $this->assertSame(
            TrainerPlayerStatus::Inactive,
            TrainerPlayer::withoutGlobalScopes()->find($mineRow->id)?->status
        );
        $this->assertSame(
            TrainerPlayerStatus::Active,
            TrainerPlayer::withoutGlobalScopes()->find($theirRow->id)?->status
        );
    }

    /** @return array{0: TrainerProfile, 1: TrainerPlayer} */
    protected function rosterRow(): array
    {
        $tenant = TrainerProfile::factory()->create();

        return [$tenant, TrainerPlayer::factory()->create(['trainer_profile_id' => $tenant->id])];
    }
}
