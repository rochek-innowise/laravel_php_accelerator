<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ShareLink;
use App\Models\User;
use App\Support\Authorization\ImpersonationGuardrail;
use PHPUnit\Framework\TestCase;

final class ImpersonationGuardrailTest extends TestCase
{
    public function test_it_denies_every_listed_ability(): void
    {
        foreach (ImpersonationGuardrail::DENIED as $ability) {
            $this->assertTrue(ImpersonationGuardrail::denies($ability));
        }
    }

    public function test_it_does_not_deny_an_unlisted_ability(): void
    {
        $this->assertFalse(ImpersonationGuardrail::denies('profile.update'));
    }

    /**
     * `delete` is shared with ShareLinkPolicy/TrainerPlayerPolicy — only deleting the account
     * itself (a User subject) is denied, matching Decision 6's "deleting the account", not
     * "deleting anything".
     */
    public function test_it_denies_delete_only_when_the_subject_is_a_user(): void
    {
        $this->assertTrue(ImpersonationGuardrail::denies('delete', new User));
    }

    public function test_it_does_not_deny_delete_for_a_non_user_subject(): void
    {
        $this->assertFalse(ImpersonationGuardrail::denies('delete', new ShareLink));
    }

    public function test_it_does_not_deny_delete_with_no_subject_at_all(): void
    {
        $this->assertFalse(ImpersonationGuardrail::denies('delete'));
    }

    /** Six forbidden abilities today; a shrinking list should fail loudly. */
    public function test_the_deny_list_covers_every_forbidden_ability(): void
    {
        $this->assertSame([
            'user.change-credentials',
            'payment-method.create',
            'payment-method.delete',
            'tokens.purchase',
            'purchase.complete',
            'respond',
        ], ImpersonationGuardrail::DENIED);
    }
}
