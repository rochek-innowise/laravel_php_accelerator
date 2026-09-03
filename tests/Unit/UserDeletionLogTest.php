<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\UserDeletionLog;
use RuntimeException;
use Tests\TestCase;

/** FR-018: the salted, comparable-across-rows email hash — not a cryptographic proof, a cheap sanity check. */
final class UserDeletionLogTest extends TestCase
{
    public function test_the_same_address_hashes_identically_regardless_of_case_or_whitespace(): void
    {
        $hash = UserDeletionLog::hashEmail('Zin@Example.Test');

        $this->assertSame($hash, UserDeletionLog::hashEmail('zin@example.test'));
        $this->assertSame($hash, UserDeletionLog::hashEmail('  zin@example.test  '));
    }

    public function test_different_addresses_do_not_collide(): void
    {
        $this->assertNotSame(
            UserDeletionLog::hashEmail('zin@example.test'),
            UserDeletionLog::hashEmail('bogdan@example.test'),
        );
    }

    public function test_it_fails_closed_when_the_salt_is_unconfigured(): void
    {
        config(['gdpr.email_hash_salt' => null]);

        $this->expectException(RuntimeException::class);

        UserDeletionLog::hashEmail('zin@example.test');
    }
}
