<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\ApprovedPurchaseExecutor;
use App\Enums\PaymentType;
use App\Models\AuditLog;
use App\Models\PurchaseApproval;
use App\Services\Approval\NullPurchaseExecutor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AD-006's seam, resolved the way production code resolves it — from the container, not
 * instantiated directly — so a rebind in AppServiceProvider is what this test would actually
 * exercise, not a hardcoded class reference.
 */
final class NullPurchaseExecutorTest extends TestCase
{
    #[Test]
    public function the_container_resolves_the_contract_to_the_null_executor(): void
    {
        $this->assertInstanceOf(NullPurchaseExecutor::class, app(ApprovedPurchaseExecutor::class));
    }

    #[Test]
    public function execute_writes_an_audit_log_entry_and_does_not_throw(): void
    {
        $approval = PurchaseApproval::factory()->approved()->create([
            'payment_type' => PaymentType::Token,
            'amount_cents' => 500,
        ]);

        app(ApprovedPurchaseExecutor::class)->execute($approval);

        $log = AuditLog::where('action', 'purchase-approval.executed')->first();

        $this->assertNotNull($log);
        $this->assertSame($approval->getKey(), $log->subject_id);
        $this->assertSame(PurchaseApproval::class, $log->subject_type);
        $this->assertSame($approval->player_profile_id, $log->metadata['player_profile_id']);
        $this->assertSame('token', $log->metadata['payment_type']);
        $this->assertSame(500, $log->metadata['amount_cents']);
    }
}
