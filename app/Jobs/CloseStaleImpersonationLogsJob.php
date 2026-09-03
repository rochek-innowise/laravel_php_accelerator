<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Http\Middleware\EnforceImpersonationTimeout;
use App\Models\ImpersonationLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * AD-008's own risk restated for a second job: `EnforceImpersonationTimeout` only catches a
 * request that actually arrives, so an admin who simply abandons the tab leaves the log open
 * forever unless this sweep runs. Closes with `ended_at = started_at + 60min` — the timeout
 * *ceiling*, not `now()` — so a sweep that runs days after an abandoned tab does not report an
 * absurd multi-day duration.
 */
final class CloseStaleImpersonationLogsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        ImpersonationLog::query()
            ->whereNull('ended_at')
            ->where('started_at', '<', now()->subMinutes(EnforceImpersonationTimeout::TIMEOUT_MINUTES))
            ->chunkById(200, function (Collection $logs): void {
                foreach ($logs as $log) {
                    $this->close($log);
                }
            });
    }

    protected function close(ImpersonationLog $log): void
    {
        $endedAt = $log->started_at->copy()->addMinutes(EnforceImpersonationTimeout::TIMEOUT_MINUTES);

        $log->forceFill([
            'ended_at' => $endedAt,
            'duration_seconds' => EnforceImpersonationTimeout::TIMEOUT_MINUTES * 60,
        ])->save();
    }
}
