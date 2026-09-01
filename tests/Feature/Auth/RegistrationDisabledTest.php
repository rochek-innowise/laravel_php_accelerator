<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * AD-004 / BR-003: registration has no route at all in Slice A. The only registration surface
 * will be /join/{code} in Slice B, so a scaffolded /register must not exist.
 */
final class RegistrationDisabledTest extends TestCase
{
    public function test_the_registration_page_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_cannot_be_posted_to(): void
    {
        $this->post('/register', [
            'email' => 'intruder@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
    }
}
