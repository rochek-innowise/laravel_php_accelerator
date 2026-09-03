<?php

declare(strict_types=1);

namespace Tests\Feature\Impersonation;

use App\Jobs\CloseStaleImpersonationLogsJob;
use App\Models\ImpersonationLog;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AD-008's own risk restated: EnforceImpersonationTimeout only catches a request that arrives, so
 * this sweep is what catches an abandoned tab. The ceiling — started_at + 60min, not now() — is
 * the one detail most likely to regress silently.
 */
final class CloseStaleImpersonationLogsJobTest extends TestCase
{
    #[Test]
    public function an_abandoned_log_is_closed_with_the_timeout_ceiling_not_now(): void
    {
        $startedAt = now()->subDays(3);
        $log = ImpersonationLog::factory()->create([
            'started_at' => $startedAt,
            'ended_at' => null,
        ]);

        app(CloseStaleImpersonationLogsJob::class)->handle();

        $fresh = $log->fresh();
        $this->assertNotNull($fresh->ended_at);
        $this->assertSame(
            $startedAt->copy()->addMinutes(60)->toDateTimeString(),
            $fresh->ended_at->toDateTimeString(),
        );
        $this->assertSame(3600, $fresh->duration_seconds);
    }

    #[Test]
    public function a_fresh_log_still_inside_the_timeout_window_is_left_alone(): void
    {
        $log = ImpersonationLog::factory()->create([
            'started_at' => now()->subMinutes(10),
            'ended_at' => null,
        ]);

        app(CloseStaleImpersonationLogsJob::class)->handle();

        $fresh = $log->fresh();
        $this->assertNull($fresh->ended_at);
        $this->assertNull($fresh->duration_seconds);
    }

    #[Test]
    public function an_already_closed_log_is_left_untouched(): void
    {
        $log = ImpersonationLog::factory()->ended()->create([
            'started_at' => now()->subDays(3),
        ]);
        $originalEndedAt = $log->ended_at;

        app(CloseStaleImpersonationLogsJob::class)->handle();

        $this->assertSame($originalEndedAt->toDateTimeString(), $log->fresh()->ended_at->toDateTimeString());
    }

    #[Test]
    public function the_job_is_registered_on_the_schedule(): void
    {
        $registered = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => $event->description === CloseStaleImpersonationLogsJob::class);

        $this->assertTrue($registered, 'CloseStaleImpersonationLogsJob is not registered in routes/console.php.');
    }
}
