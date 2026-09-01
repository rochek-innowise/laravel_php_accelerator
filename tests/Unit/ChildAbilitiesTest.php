<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Authorization\ChildAbilities;
use PHPUnit\Framework\TestCase;

final class ChildAbilitiesTest extends TestCase
{
    public function test_it_reports_a_listed_ability_as_denied(): void
    {
        foreach (ChildAbilities::DENIED as $ability) {
            $this->assertTrue(ChildAbilities::denies($ability));
        }
    }

    public function test_it_does_not_deny_an_unlisted_ability(): void
    {
        $this->assertFalse(ChildAbilities::denies('profile.update'));
    }

    /** FR-011 lists eight forbidden actions; a shrinking list should fail loudly. */
    public function test_the_deny_list_covers_every_forbidden_action(): void
    {
        $this->assertSame([
            'trainer.associate',
            'payment-method.create',
            'payment-method.delete',
            'tokens.purchase',
            'purchase.complete',
            'account.delete',
            'trainer-association.change',
            'parent-data.view',
        ], ChildAbilities::DENIED);
    }
}
