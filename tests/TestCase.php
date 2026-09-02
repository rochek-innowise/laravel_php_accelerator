<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Tests must not depend on the frontend build. Without this, every test that renders a page
     * fails the moment `public/build/manifest.json` is absent — which is the normal state of a
     * fresh clone and of a CI job that builds assets separately. The build itself is verified by
     * running it, not by asserting on its output from here.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
