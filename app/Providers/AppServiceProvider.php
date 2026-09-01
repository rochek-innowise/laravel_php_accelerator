<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TODO(coder): Gate::before granting Super Admin ONLY when session('impersonator_id') is
        // absent — an admin id leaking into the gate during impersonation is a privilege hole.

        // TODO(coder): a second Gate::before returning false for ChildAbilities::denies($ability)
        // when the authenticated user is a child account. Fail-closed, short-circuiting.
    }
}
