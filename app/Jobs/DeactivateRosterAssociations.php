<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TrainerPlayerStatus;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Support\Tenancy\TrainerContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * AD-002 made concrete: a job that touches tenant-owned rows.
 *
 * It serializes the tenant **id**, never the context, and re-resolves inside `runFor()`. A queued
 * job has no session, so without that wrapper the fail-closed scope would make every query return
 * zero rows and the job would succeed having done nothing at all — the silent failure AD-021 calls
 * the most likely subtle bug in this epic. `QueuedJobTenancyTest` asserts both halves.
 */
final class DeactivateRosterAssociations implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $trainerProfileId) {}

    /** A roster flip is worth retrying: the failure modes here are lock waits, not bad data. */
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TrainerContext $context): void
    {
        $tenant = TrainerProfile::find($this->trainerProfileId);

        if ($tenant === null) {
            return;
        }

        $context->runFor($tenant, function (): void {
            TrainerPlayer::query()->update(['status' => TrainerPlayerStatus::Inactive]);
        });
    }
}
