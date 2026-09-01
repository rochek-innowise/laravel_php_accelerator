<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

/**
 * NFR-008. An HTTP-level test cannot prove this: ValidateCsrfToken short-circuits whenever
 * `runningUnitTests()` is true, so a request without a token succeeds in the suite no matter how
 * the application is configured. What is verifiable — and what is actually ours to get wrong — is
 * that the middleware carries no exemptions and that every posting form emits a token.
 */
final class CsrfProtectionTest extends TestCase
{
    public function test_no_route_is_exempt_from_csrf_verification(): void
    {
        $this->assertSame([], (new ValidateCsrfToken(app(), app('encrypter')))->getExcludedPaths());
    }

    public function test_every_blade_form_that_posts_carries_a_csrf_token(): void
    {
        $offenders = [];

        foreach ($this->bladeTemplates() as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'method="POST"') && ! str_contains($contents, '@csrf')) {
                $offenders[] = str_replace(resource_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, 'Blade templates post without @csrf.');
    }

    /** Guards the test above: an empty glob would make it pass vacuously. */
    public function test_the_template_scan_actually_finds_posting_forms(): void
    {
        $posting = array_filter(
            $this->bladeTemplates(),
            fn (string $file): bool => str_contains((string) file_get_contents($file), 'method="POST"'),
        );

        $this->assertGreaterThanOrEqual(4, count($posting));
    }

    /** @return list<string> */
    protected function bladeTemplates(): array
    {
        $directory = new \RecursiveDirectoryIterator(resource_path('views'));
        $files = [];

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
