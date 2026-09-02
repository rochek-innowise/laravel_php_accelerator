<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentType;
use PHPUnit\Framework\TestCase;

final class PurchaseApprovalEnumsTest extends TestCase
{
    public function test_every_approval_status_has_a_label(): void
    {
        foreach (ApprovalStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }

    public function test_only_pending_is_non_terminal(): void
    {
        $this->assertFalse(ApprovalStatus::Pending->isTerminal());
        $this->assertTrue(ApprovalStatus::Approved->isTerminal());
        $this->assertTrue(ApprovalStatus::Denied->isTerminal());
        $this->assertTrue(ApprovalStatus::Expired->isTerminal());
    }

    public function test_approval_status_cases_are_exactly_the_ratified_state_machine(): void
    {
        $this->assertSame(
            ['pending', 'approved', 'denied', 'expired'],
            array_map(fn (ApprovalStatus $status): string => $status->value, ApprovalStatus::cases()),
        );
    }

    public function test_every_payment_type_has_a_label(): void
    {
        foreach (PaymentType::cases() as $type) {
            $this->assertNotSame('', $type->label());
        }
    }

    public function test_payment_type_cases_are_exactly_usd_and_token(): void
    {
        $this->assertSame(
            ['usd', 'token'],
            array_map(fn (PaymentType $type): string => $type->value, PaymentType::cases()),
        );
    }
}
