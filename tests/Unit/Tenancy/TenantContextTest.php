<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Models\TrainerProfile;
use App\Support\Tenancy\TrainerContext;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * `runFor()` is the only tenancy primitive available outside HTTP, so its restoration behaviour is
 * the thing worth pinning: a worker process runs many jobs, and a stale tenant left behind by a
 * throwing job would hand the next one another organisation's context (AD-002).
 */
final class TenantContextTest extends TestCase
{
    #[Test]
    public function it_starts_with_no_tenant(): void
    {
        $context = new TrainerContext;

        $this->assertNull($context->get());
        $this->assertNull($context->id());
        $this->assertFalse($context->has());
    }

    #[Test]
    public function run_for_restores_the_previous_tenant(): void
    {
        $outer = TrainerProfile::factory()->create();
        $inner = TrainerProfile::factory()->create();

        $context = new TrainerContext;
        $context->set($outer);

        $returned = $context->runFor($inner, fn (): int => $context->id() ?? 0);

        $this->assertSame($inner->id, $returned);
        $this->assertSame($outer->id, $context->id());
    }

    #[Test]
    public function run_for_restores_the_previous_tenant_after_a_throw(): void
    {
        $outer = TrainerProfile::factory()->create();
        $inner = TrainerProfile::factory()->create();

        $context = new TrainerContext;
        $context->set($outer);

        try {
            $context->runFor($inner, function (): never {
                throw new RuntimeException('the job failed');
            });
        } catch (RuntimeException) {
            // The exception is the point; what matters is the state left behind.
        }

        $this->assertSame($outer->id, $context->id());
    }

    #[Test]
    public function run_for_restores_a_null_tenant(): void
    {
        $tenant = TrainerProfile::factory()->create();
        $context = new TrainerContext;

        $context->runFor($tenant, fn (): null => null);

        $this->assertNull($context->get());
    }

    #[Test]
    public function run_as_system_suppresses_and_restores(): void
    {
        $context = new TrainerContext;

        $this->assertFalse($context->isSuppressed());

        // Recorded into an array rather than returned: the mutation happens inside runAsSystem, so
        // a directly returned bool reads to static analysis as a constant false.
        $observed = [];
        $context->runAsSystem(function () use (&$observed, $context): void {
            $observed[] = $context->isSuppressed();
        });

        $this->assertSame([true], $observed);
        $this->assertFalse($context->isSuppressed());
    }

    /**
     * The combination is the dangerous one: `runFor` inside `runAsSystem` used to keep the scope
     * suppressed, so the inner work read as scoped to one organisation while running across all of
     * them — the exact failure the fail-closed design exists to prevent, and one that leaves no
     * trace in the result.
     */
    #[Test]
    public function run_for_nested_inside_run_as_system_is_scoped_again(): void
    {
        $tenant = TrainerProfile::factory()->create();
        $context = new TrainerContext;

        $observed = [];

        $context->runAsSystem(function () use (&$observed, $context, $tenant): void {
            $observed['outer'] = $context->isSuppressed();

            $context->runFor($tenant, function () use (&$observed, $context): void {
                $observed['inner'] = $context->isSuppressed();
            });

            $observed['restored'] = $context->isSuppressed();
        });

        $this->assertSame(
            ['outer' => true, 'inner' => false, 'restored' => true],
            $observed,
        );
    }

    #[Test]
    public function run_as_system_restores_after_a_throw(): void
    {
        $context = new TrainerContext;

        try {
            $context->runAsSystem(function (): never {
                throw new RuntimeException('redemption failed');
            });
        } catch (RuntimeException) {
            // Same reasoning as above: suppression must not survive the failure.
        }

        $this->assertFalse($context->isSuppressed());
    }
}
